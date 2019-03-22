<?php
/**
 * Детектор открытых пулл-реквестов, в которых не проставлен авторский аппрув.
 *
 * @since #VDS-1210
 *
 * @author Dmitry Voronin <dmv@VDS.org>
 */
namespace app\modules\VDS\services\detectors;

use app\modules\VDS\models\TelegramChat;

class AuthApproveDetector extends DetectorAbstract
{
    /** {@inheritdoc} */
    public function detect()
    {
        if (!empty($this->_config['offset'])) {
            $this->filterPullRequests($this->_config['offset']);
        }

        $logInfo = [];
        foreach ($this->_openPullRequests as $pullRequest) {
            if (!$pullRequest->isOwnerApprove()) {
                $jiraTicket = $pullRequest->getJiraTicket();

                $logInfo[$jiraTicket->getOwnerKey()]['displayName'] = $jiraTicket->getOwnerDisplayName();
                $logInfo[$jiraTicket->getOwnerKey()]['tickets'][] = [
                    'key' => $pullRequest->getTaskKey(),
                    'description' => $jiraTicket->getSummaryFiltred(),
                    'link' => $pullRequest->getLink(),
                ];
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
        $notifyArray = [];

        foreach ($this->_log as $userId => $tasksInfo) {
            $name = $tasksInfo['displayName'];
            $chatId = '';

            $telegramUserInfo = TelegramChat::getTcInfoByJiraLogin($userId);
            if ($telegramUserInfo) {
                $name = $telegramUserInfo['name'];
                $chatId = $telegramUserInfo['id'];
            }

            $step = 1;
            $message = [];

            foreach ($tasksInfo['tickets'] as $ticket) {
                $message[] = sprintf(
                    "\n\n" . '%d. [%s](%s)',
                    $step++,
                    $ticket['key'] . ': ' . $ticket['description'],
                    $ticket['link']
                );
            }

            $notifyArray[] = [
                'chatId' => $chatId,
                'message' => $name . ', вы забыли поставить свой авторский аппрув по задачам:' . implode('', $message),
            ];
        }

        return $notifyArray;
    }
}