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

        $this->replyWithMessage([
            'text' => 'Press on a button',
            'reply_markup' => $this->buildKeyboard(),
        ]);
    }

    /**
     * @throws JsonException
     */
    private function buildKeyboard(): false|string
    {
        return json_encode([
            'keyboard' => [
                [
                    ['text' => '🎲 Random Number']
                ],
                [
                    ['text' => '🎲 Inline Keyboard']
                ],
                [
                    ['text' => 'Void']
                ],
            ]
        ], JSON_THROW_ON_ERROR);
    }
}
