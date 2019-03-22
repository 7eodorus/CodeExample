<?php
/**
 * Тул который раз в час проверяет задачи на код-ревью (отправляет уведомления о незакрытых пуллреквестах, например)
 * @example php yii.php code-review-notifier/index 0
 */

namespace app\modules\VDS\commands;

use app\modules\VDS\components\TelegramBot;
use app\modules\VDS\services\CodeReviewService;
use app\modules\VDS\services\detectors\DetectorAbstract;
use app\modules\VDS\models\TelegramChat;
use VDS\RdsSystem\Cron\SingleInstanceController;
use Yii;

class CodeReviewNotifierController extends SingleInstanceController
{
    /**
     * @param int $anytime
     */
    public function actionIndex($anytime)
    {
        $isItGoodTimeToTalk = $this->isItGoodTimeToTalk();

        if (!$anytime && !$isItGoodTimeToTalk) {
            Yii::info("status=skip, reason=is_not_the_time");

            return;
        }

        $codeReviewService = new CodeReviewService();
        $chats = TelegramChat::getChatsWithNotify();
        $detectors = $this->module->codeReviewNotifier['detectors'];

        foreach ($chats as $chat) {
            $jiraProjects = explode(',', $chat->tc_jira_projects);
            $openPullRequests = $codeReviewService->getOpenPullRequests($jiraProjects);

            if ($openPullRequests['count'] === 0) {
                return;
            }

            // dmv: проверяем открытые пулл-реквесты на детекторы
            foreach ($detectors as $detectorClass => $config) {
                if (!$config['enable']) {
                    continue;
                }

                /** @var DetectorAbstract $detector */
                $detector = new $detectorClass($chat, $openPullRequests['requests'], $config);

                if ($detector->detect()) {
                    $this->getTelegramBot()->notifyAboutDetect($chat->tc_id, $detector->notify());
                }
            }
        }
    }

    /**
     * Определяет, можно ли сейчас работать и стоит ли отправлять сообщение в чат.
     *
     * @return boolean|string   false если не время говорить, true если да, string если есть что добавить к обычному сообщению
     */
    private function isItGoodTimeToTalk()
    {
        // ...
        return true;
    }

    /**
     * @return TelegramBot
     */
    private function getTelegramBot()
    {
        return $this->module->telegramBot;
    }
}
