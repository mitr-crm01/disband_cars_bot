<?php

namespace App\Telegram\Commands;

use App\Models\TelegramUser;
use JsonException;
use Telegram\Bot\Commands\Command;

class StartCommand extends Command
{
    protected string $name = 'start';

    /**
     * @inheritDoc
     */
    public function handle(): void
    {
        $user = $this->getUpdate()->getMessage()->getFrom();

        $telegramUser = TelegramUser::updateOrCreate(
            ['telegram_id' => $user->getId()],
            [
                'first_name' => $user->getFirstName(),
                'last_name' => $user->getLastName() ?? null,
                'username' => $user->getUsername() ?? null,
                'language_code' => $user->getLanguageCode() ?? null,
                'is_premium' => $user->isPremium() ?? false,
            ]
        );

        if ($telegramUser) {

            if (empty($telegramUser->phone_number)) {

                $this->replyWithMessage([
                    'text' => $telegramUser->first_name . ', поделитесь номером телефона:',
                    'reply_markup' => $this->buildNumberKeyboard(),
                ]);

            } else {

                $this->replyWithMessage([
                    'text' => $telegramUser->first_name . ', выберите опцию:',
                    'reply_markup' => $this->buildKeyboard(),
                ]);

            }
        }


    }

    /**
     * @throws JsonException
     */
    private function buildKeyboard(): false|string
    {
        return json_encode([
            'keyboard' => [
                [
                    ['text' => '📋 Доступные автовозы']
                ],
                [
                    ['text' => '🗄 Архив расформирований']
                ]
            ]
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    private function buildNumberKeyboard(): false|string
    {
        return json_encode([
            'keyboard' => [
                [
                    ['text' => '🤙 Поделиться номером телефона', 'request_contact' => true],
                ],
            ]
        ], JSON_THROW_ON_ERROR);
    }
}
