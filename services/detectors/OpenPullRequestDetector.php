<?php
/**
 * Детектор открытых пулл-реквестов
 *
 * @since #VDS-1210
 *
 * @author Dmitry Voronin <dmv@VDS.org>
 */
namespace app\modules\VDS\services\detectors;

use Yii;
use app\modules\VDS\components\PullRequest;

class OpenPullRequestDetector extends DetectorAbstract
{
    /**
     * @string Ключ доступа к данным о погоде
     */
    const WEATHER_API_KEY = '1b8c07128110107f0263adb231a9b6ca';

    /** {@inheritdoc} */
    public function detect()
    {
        $this->filterPullRequests($this->_chat->tc_pull_request_updated_offset);

        return true;
    }

    /** {@inheritdoc} */
    public function notify()
    {
        $index = 1;
        $notify = [];

        foreach ($this->_openPullRequests as $pullRequestItem) {
            // dz: формируем постоянное сообщение
            $text = sprintf(
                "\n\n" . '%d. %s %s %s[%s](%s)%s ждет *%s*',
                $index++,
                $pullRequestItem->isOwnerApprove() ? '✅ ' : '',
                ($pullRequestItem->getDiffsCount() > $this->_config['complexityLimit'] ? '' : self::SMALL_SIZE_ICON),
                $pullRequestItem->isNeedWork() ? '⛔️ ' : '',
                $pullRequestItem->getTaskKey() . ':' . $pullRequestItem->getJiraTicket()->getSummaryFiltred(),
                $pullRequestItem->getLink(),
                $pullRequestItem->getJiraTicket()->isExpedite() ? '🚀' : '',
                $this->prepareTime($pullRequestItem->timeWaitFromLastUpdated())
            );
            // dz: находим время ожидания
            if ($pullRequestItem->getCreateTime() <> $pullRequestItem->getUpdateTime()) {
                $text .= sprintf(' из %s', $this->prepareTime($pullRequestItem->timeWaitFromCreated()));
            }
            // dz: список тех, кто уже оставил аппрув
            if ($pullRequestItem->getApprovedUser()) {
                $text .= sprintf(' (уже есть аппрув от %s)', implode(', ', $pullRequestItem->getApprovedUserName()));
            }
            // dz: формируем сообщение о дедлайнах
            $textDeadline = $this->getDeadlineNotify($pullRequestItem);
            if ($textDeadline) {
                $text .=  sprintf(' 📆 дедлайн %s', $textDeadline);
            }

            $notify[] = $text;
        }

        $notify = implode('', $notify);
        $notify .= "\n\n" . Yii::t(
            'app',
            'Всего {n,plural,=0{нет задач} =1{# задача} =2{# задачи} =3{# задачи} =4{# задачи} other{# задач}} висит на ревью',
            ['n' => count($this->_openPullRequests)]
        );

        return [
            [
                'chatId' => $this->_chat->tc_id,
                'message' => $this->getNotifyPrefix() . $notify,
            ]
        ];
    }

    /** {@inheritdoc} */
    protected function filterPullRequests($offset)
    {
        // dmv: для общего сообщения об открытых пул-реквестах, итемы с собственным аппрувом показываем всегда @WTT-5785
        $pullRequests = $this->_openPullRequests;
        $this->_openPullRequests = array_filter($pullRequests, function (PullRequest $item) use ($offset) {
            return $item->isOwnerApprove() || ($item->timeWaitFromLastUpdated() > $offset);
        });
    }

    /**
     * Префикс для сообщения о пул-реквестах
     *
     * @return string
     */
    private function getNotifyPrefix()
    {
        if (!$this->_config['addPrefixToMessage']) {
            return '';
        }

        $texts = [
            'Время ревьюить!',
            'Столько задач, а никто не смотрит :(',
            'Скучно? Не знаешь чем заняться? Посмотри задачи на кодревью!',
            'Сделай полезное дело - заапрувь реквест!',
            'Уже все посмотрели свежие задачки на ревью?',
            'Самое время заняться ревью.',
        ];
        $text = $texts[array_rand($texts)];

        if (mt_rand(0, 10) < 5) {
            $text = $this->getCurrentWeatherAndLocationText() . ' ' . $text;
        }

        return $text;
    }

    /**
     * Метод возвращает строку с информацией о погоде и времени суток иногда добавляя что-то для разнообразия к строке
     *
     * @author Dmitry Vorobyev
     *
     * @return string
     */
    protected function getCurrentWeatherAndLocationText()
    {
        $cities = [
            [
                'code' => 'New York,US',
                'text' => 'Нью-Йорке',
                'funny' =>
                    [
                        ' на Брайтон бич',
                        ', да и метро хуже нашего',
                        '',
                        '',
                        '',
                    ],
            ],
            [
                'code' => 'Moscow,RU',
                'text' => 'Москве',
                'funny' =>
                    [
                        ' у Собянина',
                        ', а они плитку перекладывают',
                        ', а все говорят что нерезиновая',
                        '. А еще медведи по улицам гуляют',
                        ', хотя при Лужкове такого не было',
                        '',
                    ],
            ],
            [
                'code' =>
                    'Paris,FR',
                'text' => 'Париже',
            ],
            [
                'code' => 'Tokyo,JP',
                'text' => 'Токио',
            ],
            [
                'code' => 'Sydney,AU',
                'text' => 'Сиднее',
                'funny' =>
                    [
                        ', а на серф не дует',
                        '',
                        '',
                        '',
                        '',
                    ],
            ],
            [
                'code' => 'London,UK',
                'text' => 'Лондоне',
                'funny' =>
                    [
                        ' у олигархов',
                        ' у Абрамовича',
                        '',
                        '',
                        '',
                        '',
                    ],
            ],
        ];
        $city = $cities[array_rand($cities)];

        $weatherData = json_decode(
            file_get_contents('https://api.openweathermap.org/data/2.5/weather?' . http_build_query([ 'appid' => self::WEATHER_API_KEY, 'lang' => 'ru', 'q' => $city['code']])),
            true
        );

        $text = sprintf('В %s %s', $city['text'], $weatherData['weather'][0]['description']);

        if (mt_rand(0, 10) < 5) {
            // vdm: в данных есть небольшой баг: они возвращаются от текущего дня поэтому рассвет в Сиднее будет попадать не очень часто
            $sunriseDiff = time() - $weatherData['sys']['sunrise'];
            $sunsetDiff = time() - $weatherData['sys']['sunset'];

            if ($sunriseDiff < 0 && $sunriseDiff > -45 * 60) {
                $text .= ' и уже скоро рассвет';
            } elseif ($sunriseDiff > 0 && $sunriseDiff < 45 * 60) {
                $text .= ' и рассвело';
            } elseif ($sunsetDiff < 0 && $sunsetDiff > -45 * 60) {
                $text .= ' и начинает темнеть';
            } elseif ($sunsetDiff > 0 && $sunsetDiff < 45 * 60) {
                $text .= ' и стемнело';
            }
        }

        if (mt_rand(0, 50) < 5 && !empty($city['funny'])) {
            $text .= $city['funny'][array_rand($city['funny'])];
        }

        $text .= '.';

        return $text;
    }
}