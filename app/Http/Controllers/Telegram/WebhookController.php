<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Models\Carrier;
use App\Models\TelegramUser;
use App\Models\TelegramUserState;
use App\Telegram\Queries\AbstractQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                        'reply_markup' => $this->buildKeyboard(['📋 Доступные автовозы', '🗄 Архив расформирований']),
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

                if (!str_starts_with($text, '/')) {

                    $telegramUser = TelegramUser::where('telegram_id', $chat_id)->first();

                    $is_allowed = $telegramUser->state->is_allowed;
//                    $state = $telegramUser->state->state;

                    if ($is_allowed && !empty($telegramUser->phone_number)) {

                        $state = $telegramUser->state->getCurrentState();

                        $baseState = explode('-', $state)[0];

//                        Log::info($baseState);

                        switch ($baseState) {

                            case 'initial':
                                $this->handleInitialState($event, $telegramUser, $text);
                                break;

                            case 'disbandment_archive':
                                $this->handleDisbandmentArchiveState($event, $telegramUser, $text);
                                break;

                            case 'available_carriers':
                                $this->handleAvailableCarriersState($event, $telegramUser, $text);
                                break;

                            case 'selected_carriers':
                                $this->handleSelectedCarriersState($event, $telegramUser, $text);
                                break;

                            case 'selected_car':
                                $this->handleSelectedCarState($event, $telegramUser, $text);
                                break;

                            case 'accept_disband_car':
                                $this->handleAcceptDisbandCarState($event, $telegramUser, $text);
                                break;

                            case 'available_month':
                                $this->handleAvailableMonthState($event, $telegramUser, $text);
                                break;

                            case 'selected_month':
                                $this->handleSelectedMonthState($event, $telegramUser, $text);
                                break;

                            case 'selected_number':
                                $this->handleSelectedNumberState($event, $telegramUser, $text);
                                break;

                        }

                    } else {

                        $event->telegram->sendMessage([
                            'chat_id' => $chat_id,
                            'text' => "Скоро предоставят доступ",
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
            Log::error('Произошла ошибка: ' . $exception->getMessage());
            return response()
                ->json([
                    'status' => false,
                    'error' => $exception->getMessage()
                ]);
        }
    }

    private function handleInitialState(UpdateEvent $event, $telegramUser, $text)
    {
        if ($text == '📋 Доступные автовозы') {

            $telegramUser->state->addState('available_carriers');

            $carriersData = DB::connection('mysql_dbmihold')
                ->table('b_crm_deal')
                ->select('ID', 'TITLE')
                ->where('STAGE_ID', 'C87:UC_5Y16D8')
                ->get();

            $carriers = [];

            foreach ($carriersData as $deal) {
                if (str_starts_with($deal->TITLE, 'Автовоз ')) {
                    $carriers[] = str_replace('Автовоз ', '', $deal->TITLE);
                    Carrier::updateOrCreate(['b_id' => $deal->ID], ['b_title' => $deal->TITLE]);

                }
            }

            $carriers[] = 'Назад';

            $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => 'Выбери автовоз который надо расформировать',
                'reply_markup' => $this->buildKeyboard($carriers)
            ]);

        } elseif ($text == '🗄 Архив расформирований') {

            $telegramUser->state->addState('disbandment_archive');

            $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => 'Архив в разработке',
                'reply_markup' => $this->buildKeyboard(['Назад'])
            ]);

        } else {

            $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => 'Не промахивайся по кнопкам!',
            ]);

        }
    }

    private function handleDisbandmentArchiveState(UpdateEvent $event, $telegramUser, $text)
    {

        if ($text == 'Назад') {

            $telegramUser->state->removeLastState();

            return $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => $telegramUser->first_name . ', выберите опцию:',
                'reply_markup' => $this->buildKeyboard(['📋 Доступные автовозы', '🗄 Архив расформирований'])
            ]);

        }
    }

    private function handleAvailableCarriersState(UpdateEvent $event, $telegramUser, $text)
    {

        if ($text == 'Назад') {

            $telegramUser->state->removeLastState();

            return $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => 'Вы вернулись в главное меню',
                'reply_markup' => $this->buildKeyboard(['📋 Доступные автовозы', '🗄 Архив расформирований'])
            ]);

        } else {

            $carrier = Carrier::where('b_title', "Автовоз $text")->first();

            $telegramUser->state->addState("selected_carriers-$carrier->b_id");

            $carsData = DB::connection('mysql_dbmihold')
                ->table('b_uts_crm_deal')
                ->select('UF_CRM_1663349303')
                ->where('VALUE_ID', $carrier->b_id)
                ->first();

            $idsCars = unserialize($carsData->UF_CRM_1663349303);

            $cars = [];

            foreach ($idsCars as $idcar) {

                $car = DB::connection('mysql_dbmihold')
                    ->table('b_crm_deal')
                    ->select('TITLE')
                    ->where('ID', $idcar)
                    ->first();

                $cars[] = $car->TITLE;

            }

            $cars[] = 'Расформировать всё';
            $cars[] = 'Назад';

            return $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => "Выбран $text\nАвтомобили на данном автовозе:\n",
                'reply_markup' => $this->buildKeyboard($cars)
            ]);

        }
    }

    private function handleSelectedCarriersState(UpdateEvent $event, $telegramUser, $text)
    {

        if ($text == 'Назад') {

            $telegramUser->state->removeLastState();

            $carriersData = DB::connection('mysql_dbmihold')
                ->table('b_crm_deal')
                ->select('ID', 'TITLE')
                ->where('STAGE_ID', 'C87:UC_5Y16D8')
                ->get();

            $carriers = [];

            foreach ($carriersData as $deal) {
                if (str_starts_with($deal->TITLE, 'Автовоз ')) {
                    $carriers[] = str_replace('Автовоз ', '', $deal->TITLE);
                    Carrier::updateOrCreate(['b_id' => $deal->ID], ['b_title' => $deal->TITLE]);
                }
            }

            $carriers[] = 'Назад';

            return $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => 'Выбери автовоз который надо расформировать',
                'reply_markup' => $this->buildKeyboard($carriers)
            ]);

        } else if ($text == 'Расформировать всё') {

            $telegramUser->state->addState("available_month");

            return $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => "Выберите сегодняшний месяц:",
                'reply_markup' => $this->buildKeyboard([
                    '1 Январь', '2 Февраль', '3 Март', '4 Апрель', '5 Май', '6 Июнь',
                    '7 Июль', '8 Август', '9 Сентябрь', '10 Октябрь', '11 Ноябрь', '12 Декабрь',
                    'Назад'
                ])
            ]);

        } else {

            $CarId = DB::connection('mysql_dbmihold')
                ->table('b_crm_deal')
                ->where('TITLE', $text)
                ->value('ID');

            $telegramUser->state->addState("selected_car-$CarId");

            return $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => "Вы выбрали $text",
                'reply_markup' => $this->buildKeyboard([
                    'Расформировать на ЧЛС', 'Назад'
                ])
            ]);

        }

    }

    private function handleSelectedCarState(UpdateEvent $event, $telegramUser, $text)
    {

        if ($text == 'Назад') {

            $telegramUser->state->removeLastState();

            $state = $telegramUser->state->getCurrentState();

//            Log::info('Назад: ' . $state);

            $baseState = explode('-', $state)[1];

            $carrier = Carrier::where('b_id', $baseState)->first();

            $carsData = DB::connection('mysql_dbmihold')
                ->table('b_uts_crm_deal')
                ->select('UF_CRM_1663349303')
                ->where('VALUE_ID', $baseState)
                ->first();

            $idsCars = unserialize($carsData->UF_CRM_1663349303);

            $cars = [];

            foreach ($idsCars as $idcar) {

                $car = DB::connection('mysql_dbmihold')
                    ->table('b_crm_deal')
                    ->select('TITLE')
                    ->where('ID', $idcar)
                    ->first();

                $cars[] = $car->TITLE;

            }

            $cars[] = 'Расформировать всё';
            $cars[] = 'Назад';

            return $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => "Выбран $carrier->b_title\nАвтомобили на данном автовозе:",
                'reply_markup' => $this->buildKeyboard($cars)
            ]);

        } else if ($text == 'Расформировать на ЧЛС') {

            $state = $telegramUser->state->getCurrentState();

            $baseState = explode('-', $state)[1];

            $CarTitle = DB::connection('mysql_dbmihold')
                ->table('b_crm_deal')
                ->where('ID', $baseState)
                ->value('TITLE');

            $telegramUser->state->addState("accept_disband_car");

            return $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => "Проверка вводных данных:\nВыбран $CarTitle\nРасформировать на ЧЛС?",
                'reply_markup' => $this->buildKeyboard([
                    'Подтвердить', 'Назад'
                ])
            ]);

        }

    }

    private function handleAvailableMonthState(UpdateEvent $event, $telegramUser, $text)
    {

        if ($text == 'Назад') {

            $telegramUser->state->removeLastState();

            $state = $telegramUser->state->getCurrentState();

            $baseState = explode('-', $state)[1];

            $carrier = Carrier::where('b_id', $baseState)->first();

            $carsData = DB::connection('mysql_dbmihold')
                ->table('b_uts_crm_deal')
                ->select('UF_CRM_1663349303')
                ->where('VALUE_ID', $baseState)
                ->first();

            $idsCars = unserialize($carsData->UF_CRM_1663349303);

            $cars = [];

            foreach ($idsCars as $idcar) {

                $car = DB::connection('mysql_dbmihold')
                    ->table('b_crm_deal')
                    ->select('TITLE')
                    ->where('ID', $idcar)
                    ->first();

                $cars[] = $car->TITLE;

            }

            $cars[] = 'Расформировать всё';
            $cars[] = 'Назад';

            return $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => "Выбран $carrier->b_title\nАвтомобили на данном автовозе:",
                'reply_markup' => $this->buildKeyboard($cars)
            ]);

        } else {

            $parts = explode(' ', $text);

            $telegramUser->state->addState("selected_month-$parts[0]");

            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $parts[0], date('Y'));
            $days = [];

            // Создаем массив кнопок для каждого дня
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $days[] = strval($day);
            }

            // Добавляем кнопку "Назад"
            $days[] = 'Назад';

            return $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => "Выбран $text\nВыберите сегодняшнее число:",
                'reply_markup' => $this->buildKeyboard($days)
            ]);

        }

    }

    private function handleSelectedMonthState(UpdateEvent $event, $telegramUser, $text)
    {

        if ($text == 'Назад') {

            $telegramUser->state->removeLastState();

            return $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => "Выберите сегодняшний месяц:",
                'reply_markup' => $this->buildKeyboard([
                    '1 Январь', '2 Февраль', '3 Март', '4 Апрель', '5 Май', '6 Июнь',
                    '7 Июль', '8 Август', '9 Сентябрь', '10 Октябрь', '11 Ноябрь', '12 Декабрь',
                    'Назад'
                ])
            ]);

        } else {

            $telegramUser->state->addState("selected_number-$text");

            $fullState = $telegramUser->state->state;

            preg_match('/selected_carriers-(\d+):available_month:selected_month-(\d+):selected_number-(\d+)/', $fullState, $matches);

            if ($matches) {
                $selectedCarriers = $matches[1];
                $selectedMonth = $matches[2];
                $selectedNumber = $matches[3];
            }

            $currentYear = date('Y');

            // Приводим день и месяц к двухзначному формату
            $month = str_pad($selectedMonth, 2, '0', STR_PAD_LEFT);
            $day = str_pad($selectedNumber, 2, '0', STR_PAD_LEFT);

            // Формируем строку даты
            $dateString = "$day.$month.$currentYear";

//            Log::info($selectedCarriers);
//            Log::info($selectedMonth);
//            Log::info($selectedNumber);

            $carrier = Carrier::where('b_id', $selectedCarriers)->first();

//            Log::info($carrier->b_title);

            return $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => "Проверка вводных данных:\nВыбран $carrier->b_title\nУказанная дата расформирования: $dateString",
                'reply_markup' => $this->buildKeyboard([
                    'Подтвердить',
                    'Назад'
                ])
            ]);

        }

    }

    private function handleSelectedNumberState(UpdateEvent $event, $telegramUser, $text)
    {

        if ($text == 'Назад') {

            $telegramUser->state->removeLastState();
            $state = $telegramUser->state->getCurrentState();

            $parts = explode('-', $state);

            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$parts[1], date('Y'));
            $days = [];

            // Создаем массив кнопок для каждого дня
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $days[] = strval($day);
            }

            // Добавляем кнопку "Назад"
            $days[] = 'Назад';

            return $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => "Выбран $text\nВыберите сегодняшнее число:",
                'reply_markup' => $this->buildKeyboard($days)
            ]);

        } else if ($text == 'Подтвердить') {

//            $telegramUser->state->addState("confirm");

            $fullState = $telegramUser->state->state;

            preg_match('/selected_carriers-(\d+):available_month:selected_month-(\d+):selected_number-(\d+)/', $fullState, $matches);

            if ($matches) {
                $selectedCarriers = $matches[1];
                $selectedMonth = $matches[2];
                $selectedNumber = $matches[3];
            }

            $currentYear = date('Y');

            $month = str_pad($selectedMonth, 2, '0', STR_PAD_LEFT);
            $day = str_pad($selectedNumber, 2, '0', STR_PAD_LEFT);

            $dateString = "$day.$month.$currentYear";

            $user = $this->getBitrixUserId($telegramUser);

            if ($user) {
                Log::info('start webhook');
                $this->StartBP($selectedCarriers, $user, $dateString, '');
                Log::info('end webhook');

//                Carrier::updateOrCreate(['b_id' => $selectedCarriers],['is_disabled' => true]);

                TelegramUserState::updateOrCreate(
                    ['telegram_user_id' => $telegramUser->id],
                    ['state' => 'initial']
                );

                return $event->telegram->sendMessage([
                    'chat_id' => $telegramUser->telegram_id,
                    'text' => "Автовоз успешно расформирован!",
                    'reply_markup' => $this->buildKeyboard([
                        '📋 Доступные автовозы',
                        '🗄 Архив расформирований'
                    ])
                ]);
            } else {

                TelegramUserState::updateOrCreate(
                    ['telegram_user_id' => $telegramUser->id],
                    ['state' => 'initial']
                );

                return $event->telegram->sendMessage([
                    'chat_id' => $telegramUser->telegram_id,
                    'text' => "Что-то пошло не так! Обратитесь к руководству",
                    'reply_markup' => $this->buildKeyboard([
                        '📋 Доступные автовозы',
                        '🗄 Архив расформирований'
                    ])
                ]);

            }


        }

    }

    private function handleAcceptDisbandCarState(UpdateEvent $event, $telegramUser, $text)
    {

        if ($text == 'Назад') {

            $telegramUser->state->removeLastState();

            $state = $telegramUser->state->getCurrentState();

            $baseState = explode('-', $state)[1];

            $carrier = Carrier::where('b_id', $baseState)->first();

            $carsData = DB::connection('mysql_dbmihold')
                ->table('b_uts_crm_deal')
                ->select('UF_CRM_1663349303')
                ->where('VALUE_ID', $baseState)
                ->first();

            $idsCars = unserialize($carsData->UF_CRM_1663349303);

            $cars = [];

            foreach ($idsCars as $idcar) {

                $car = DB::connection('mysql_dbmihold')
                    ->table('b_crm_deal')
                    ->select('TITLE')
                    ->where('ID', $idcar)
                    ->first();

                $cars[] = $car->TITLE;

            }

            $cars[] = 'Расформировать всё';
            $cars[] = 'Назад';

            return $event->telegram->sendMessage([
                'chat_id' => $telegramUser->telegram_id,
                'text' => "Выбран $carrier->b_title\nАвтомобили на данном автовозе:",
                'reply_markup' => $this->buildKeyboard($cars)
            ]);

        } else if ($text == 'Подтвердить') {

//            $telegramUser->state->addState("confirm");

            $fullState = $telegramUser->state->state;

            preg_match('/initial:available_carriers:selected_carriers-(\d+):selected_car-(\d+):accept_disband_car/', $fullState, $matches);

            if ($matches) {
                $selectedCarriers = $matches[1];
                $selectedCar = $matches[2];
            }

            $carOper = DB::connection('mysql_dbmihold')
                ->table('b_uts_crm_deal')
                ->where('VALUE_ID', $selectedCar)
                ->value('UF_CRM_1695214894');

            $user = $this->getBitrixUserId($telegramUser);

            if ($user) {
                Log::info('start webhook');
                $this->StartBP($carOper, $user, false, true);
                Log::info('end webhook');

//                Carrier::updateOrCreate(['b_id' => $selectedCarriers],['is_disabled' => true]);

                TelegramUserState::updateOrCreate(
                    ['telegram_user_id' => $telegramUser->id],
                    ['state' => 'initial']
                );

                return $event->telegram->sendMessage([
                    'chat_id' => $telegramUser->telegram_id,
                    'text' => "Автомобиль успешно расформирован на ЧЛС!",
                    'reply_markup' => $this->buildKeyboard([
                        '📋 Доступные автовозы',
                        '🗄 Архив расформирований'
                    ])
                ]);
            } else {

                TelegramUserState::updateOrCreate(
                    ['telegram_user_id' => $telegramUser->id],
                    ['state' => 'initial']
                );

                return $event->telegram->sendMessage([
                    'chat_id' => $telegramUser->telegram_id,
                    'text' => "Что-то пошло не так! Обратитесь к руководству",
                    'reply_markup' => $this->buildKeyboard([
                        '📋 Доступные автовозы',
                        '🗄 Архив расформирований'
                    ])
                ]);

            }


        }

    }

    function getBitrixUserId($telegramUser)
    {
        $BitrixUserId = DB::connection('mysql_dbmihold')
            ->table('b_user')
            ->where('WORK_PHONE', $telegramUser->phone_number)
            ->where('LAST_LOGIN', '>=', now()->subDays(30))
            ->orderBy('LAST_LOGIN', 'desc')
            ->value('ID');

        return $BitrixUserId;
    }

    function StartBP($deal, $user, $date, $chls)
    {
        $baseURL = 'https://mihold.online/rest/188354/xnzkyyhsxccqi142/bizproc.workflow.start/?';
        $params = array(
            'TEMPLATE_ID' => 4451,
            'DOCUMENT_ID[0]' => 'crm',
            'DOCUMENT_ID[1]' => 'CCrmDocumentDeal',
            'DOCUMENT_ID[2]' => "D_" . $deal,
            'PARAMETERS' => array('deal' => $deal, 'user' => $user, 'date' => $date, 'chls' => $chls),
        );

        $finalURL = $baseURL . http_build_query($params);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $baseURL);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));

        Log::info('Starting cURL request to URL: ' . $baseURL);
        Log::info('Final URL: ' . $finalURL);
        Log::info('Parameters: ' . print_r($params, true));

        $response = curl_exec($ch);

        Log::info('cURL response: ' . $response);

        if ($response === false) {
            Log::info('Error: ' . curl_error($ch));
        } else {
            $result = json_decode($response, true);
            if (isset($result['error'])) {
                Log::info('Error: ' . $result['error']);
            }
        }

        curl_close($ch);
    }

    /**
     * @throws \JsonException
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

    private function buildKeyboard(array $arr): false|string
    {
        $buttons = [];

        foreach ($arr as $index => $item) {
            $buttons[] = [['text' => $item]];
        }

        return json_encode([
            'keyboard' => $buttons,
            'resize_keyboard' => true,
            'is_persistent' => true,
        ], JSON_THROW_ON_ERROR | false, 512);
    }
}
