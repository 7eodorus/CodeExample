<?php
/**
 * Детектор открытых пулл-реквестов, которые некорректно оформлены.
 *
 * @since #VDS-1210
 *
 * @author Dmitry Voronin <dmv@VDS.org>
 */
namespace app\modules\VDS\services\detectors;

use app\modules\VDS\models\TelegramChat;

class QANoticeDetector extends DetectorAbstract
{
    /** {@inheritdoc} */
    public function detect()
    {
        if (!empty($this->_config['offset'])) {
            $this->filterPullRequests($this->_config['offset']);
        }

        $logInfo = [];
        foreach ($this->_openPullRequests as $pullRequest) {
            $jiraTicket = $pullRequest->getJiraTicket();
            $comments = $jiraTicket->getComments();

            $isFill = [];
            foreach ($comments as $comment) {
                foreach ($this->_config['masks'] as $mask) {
                    if (mb_strpos($comment['body'], $mask, 0, 'UTF-8') !== false) {
                        $isFill[$mask] = true;
                    }
                }
            }

            if (!$isFill || (count($isFill) != count($this->_config['masks']))) {
                $logInfo[$jiraTicket->getOwnerKey()]['displayName'] = $jiraTicket->getOwnerDisplayName();
                $logInfo[$jiraTicket->getOwnerKey()]['tickets'][] = [
                    'key' => $pullRequest->getTaskKey(),
                    'description' => $jiraTicket->getSummaryFiltred(),
                    'link' => $pullRequest->getLink(),
                    'filledFields' => $isFill,
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
        $postfix = '';

        if (!empty($this->_config['wiki'])) {
            $postfix = sprintf(
                "\n\n" . '[%s](%s)',
                'Как правильно оформлять задачу?',
                $this->_config['wiki']
            );
        }

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
                $additionalInfo = '';

                foreach ($this->_config['masks'] as $mask) {
                    if (!isset($ticket['filledFields'][$mask])) {
                        $additionalInfo .= 'Нет блока "' . $mask . '". ';
                    }
                }

                $message[] = sprintf(
                    "\n\n" . '%d. [%s](%s), %s',
                    $step++,
                    $ticket['key'] . ': ' . $ticket['description'],
                    $ticket['link'],
                    $additionalInfo
                );
            }

            $notifyArray[] = [
                'chatId' => $chatId,
                'message' => $name . ', оформи задачу по правилам! Список задач:' . implode('', $message) . $postfix,
            ];
        }

        return $notifyArray;
    }
}