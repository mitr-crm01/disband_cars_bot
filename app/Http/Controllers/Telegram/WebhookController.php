<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Models\TelegramUser;
use App\Telegram\Queries\AbstractQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Events\UpdateEvent;
use Telegram\Bot\Laravel\Facades\Telegram;

class WebhookController extends Controller
{
    /**
     * @return JsonResponse
     */
    public function set(): JsonResponse
    {
        try {
            $bot = Telegram::bot();
            $response = $bot->setWebhook([
                'url' => config('telegram.bots.default.webhook_url')
            ]);

            return response()->json($response);
        } catch (\Throwable $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }
    }

    public function handle(Request $request): JsonResponse
    {
        try {
            Telegram::on('message.contact', function (UpdateEvent $event) {
                $message = $event->update->message;
                $chat_id = $message->from->id;
                $phone_number = $message->contact->phone_number;
                $user_id = $message->contact->user_id;

                if ($user_id === $chat_id) {

                    $telegramUser = TelegramUser::where('telegram_id', $chat_id)->first();

                    if (!str_starts_with($phone_number, '+')) {
                        $phone_number = '+' . $phone_number;
                    }

                    $telegramUser->phone_number = $phone_number;
                    $telegramUser->save();

                    $event->telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "✅ Ваш номер телефона подтверждён\nСкоро вам предоставят доступ до функций бота",
                        'reply_markup' => $this->buildKeyboard(),
                    ]);

                } else {

                    $event->telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "⚠️ Это не ваш номер телефона!\nНажмите на кнопку ниже чтобы поделиться своим номером телефона",
                        'reply_markup' => $this->buildNumberKeyboard(),
                    ]);

                }
            });

            Telegram::on('message.text', function (UpdateEvent $event) {
                $message = $event->update->message;
                $from = $message->from;
                $text = $message->text;
                $chat_id = $from->id;

                $telegramUser = TelegramUser::where('telegram_id', $chat_id)->first();

                $is_allowed = $telegramUser->state->is_allowed;
                $state = $telegramUser->state->state;

                if (!str_starts_with($text, '/')) {

                    if ($is_allowed && !empty($telegramUser->phone_number)) {

                        switch ($state) {
                            case 'initial':

                                $event->telegram->sendMessage([
                                    'chat_id' => $chat_id,
                                    'text' => "initial text",
                                ]);
                                break;
                        }

                    } else {

                        $event->telegram->sendMessage([
                            'chat_id' => $chat_id,
                            'text' => "Вам не предоставили доступ для использования бота(",
                        ]);

                    }

                }

            });

            Telegram::commandsHandler(true);

            return response()
                ->json([
                    'status' => true,
                    'error' => null
                ]);
        } catch (\Throwable $exception) {
            return response()
                ->json([
                    'status' => false,
                    'error' => $exception->getMessage()
                ]);
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
