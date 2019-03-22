<?php
/**
 * Детектор открытых пулл-реквестов.
 * Отправляет сообщение определенным разработчикам с просьбой посмотреть X задач на ревью.
 *
 * @since #VDS-1210
 *
 * @author Dmitry Voronin <dmv@VDS.org>
 */
namespace app\modules\VDS\services\detectors;

use Yii;
use app\modules\VDS\models\TelegramChat;

class TargetNoticeDetector extends DetectorAbstract
{
    /** {@inheritdoc} */
    public function detect()
    {
        if (!empty($this->_config['offset'])) {
            $this->filterPullRequests($this->_config['offset']);
        }

        $this->_openPullRequests = (array) $this->_openPullRequests;
        shuffle($this->_openPullRequests);

        $logInfo = [];
        foreach ($this->_openPullRequests as $pullRequest) {
            foreach ($this->_config['developers'] as $developer) {
                if (count($logInfo[$developer]) == $this->_config['countPullRequests']) {
                    break;
                }

                $jiraTicket = $pullRequest->getJiraTicket();

                if ($jiraTicket->getOwnerKey() != $developer
                    && !in_array($developer, $pullRequest->getApprovedUserLogin())
                    && $pullRequest->isOwnerApprove()
                ) {
                    $logInfo[$developer][] = $pullRequest;
                }
            }
        }

        if ($logInfo) {
            $this->setLog($logInfo);

            return true;
        }

        return false;
    }

    /** {@inheritdoc} */
    public function notify()
    {
        $response = [];

        foreach ($this->_log as $userId => $pullRequests) {
            $chatId = '';
            $name = '';
            $index = 1;
            $notify = [];

            $telegramUserInfo = TelegramChat::getTcInfoByJiraLogin($userId);
            if ($telegramUserInfo) {
                $name = $telegramUserInfo['name'];
                $chatId = $telegramUserInfo['id'];
            }

            foreach ($pullRequests as $pullRequestItem) {
                $jiraTicket = $pullRequestItem->getJiraTicket();

                if (empty($name)) {
                    $name = $jiraTicket->getOwnerDisplayName();
                }

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
                    $text .= sprintf(' 📆 дедлайн %s', $textDeadline);
                }

                $notify[] = $text;
            }

            $notify = implode('', $notify);
            $notify .= "\n\n" . Yii::t(
                'app',
                'Всего {n,plural,=0{нет задач} =1{# задача} =2{# задачи} =3{# задачи} =4{# задачи} other{# задач}} висит на ревью',
                ['n' => count($this->_openPullRequests)]
            );

            $response[] = [
                'chatId' => $chatId,
                'message' => $name . ', посмотрите пару задачек на ревью: ' . $notify,
            ];
        }

        return $response;
    }
}