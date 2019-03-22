<?php

namespace app\modules\VDS\services\detectors;

use app\modules\VDS\components\PullRequest;
use app\modules\VDS\models\TelegramChat;

abstract class DetectorAbstract
{
    const NAMESPACE = __NAMESPACE__;
    const SMALL_SIZE_ICON = '⛏';

    protected $_chat;
    protected $_openPullRequests = [];
    protected $_config = [];
    protected $_log = [];

    /**
     * DetectorAbstract constructor.
     *
     * @param TelegramChat $chat
     * @param PullRequest[] $openPullRequests
     * @param array $config
     */
    public function __construct($chat, $openPullRequests, $config)
    {
        $this->_chat = $chat;
        $this->_openPullRequests = $openPullRequests;
        $this->_config = $config;
    }

    /**
     * Детектор
     *
     * @return bool
     */
    abstract public function detect();

    /**
     * Генератор массива сообщений для телеграм-бота
     *
     * @return array
     */
    abstract public function notify();

    /**
     * Инициализация лога
     *
     * @param array $log
     */
    protected function setLog($log)
    {
        $this->_log = $log;
    }

    /**
     * Фильтруем список пуллреквестов
     *
     * @param int $offset
     */
    protected function filterPullRequests($offset)
    {
        $pullRequests = $this->_openPullRequests;
        $this->_openPullRequests = array_filter($pullRequests, function (PullRequest $item) use ($offset) {
            return ($item->timeWaitFromLastUpdated() > $offset);
        });
    }

    /**
     * Подготовка даты к выводу
     *
     * @param int $time - время в секундах (разница времени в секундах)
     *
     * @return string
     */
    protected function prepareTime($time)
    {
        return preg_replace('/^(.+?), .+$/', '$1', \Yii::t('app', '{n,duration,%with-words}', ['n' => $time]));
    }

    /**
     * генерация уведомлений по дедлайнам
     *
     * @param PullRequest $pullRequest
     *
     * @return string
     */
    protected function getDeadlineNotify($pullRequest)
    {
        $textDeadline = '';

        $listEnv = [
            'prod' => $pullRequest->getJiraTicket()->getProductionDeadlineTime(),
            'dev' => $pullRequest->getJiraTicket()->getDevelopmentDeadlineTime(),
        ];

        foreach ($listEnv as $env => $deadlineTime) {
            $duration = $deadlineTime - time();
            if ($duration < 0) {
                $textDeadline .= sprintf(
                    " %s *был %s назад* 🤦",
                    $env,
                    $this->prepareTime(abs($duration))
                );
            } elseif ($duration < 3 * 24 * 60 * 60) { // 3 суток
                $textDeadline .= sprintf(
                    " %s *всего через %s*",
                    $env,
                    $this->prepareTime(abs($duration))
                );
            }
        }

        return $textDeadline;
    }
}