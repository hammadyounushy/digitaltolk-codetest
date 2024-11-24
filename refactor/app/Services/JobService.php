<?php

// app/Services/JobService.php

namespace App\Services;

use App\Models\Job;
use App\Models\Translator;
use App\Models\UsersBlacklist;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use App\Events\JobWasCanceled;
use App\Events\SessionEnded;
use App\Helpers\TeHelper;
use App\Mailers\AppMailer;
use Illuminate\Support\Facades\DB;

class JobService
{
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);
        $current_translator = $this->getCurrentTranslator($job);

        $log_data = [];
        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = $this->logLanguageChange($job, $data);
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];
        $job->reference = $data['reference'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        $job->save();

        if ($job->due <= Carbon::now()) {
            return ['Updated'];
        } else {
            if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $old_time);
            if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            if ($langChanged) $this->sendChangedLangNotification($job, $old_lang);
        }
    }

    private function getCurrentTranslator($job)
    {
        $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($current_translator)) {
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();
        }
        return $current_translator;
    }

    private function logLanguageChange($job, $data)
    {
        return [
            'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
            'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
        ];
    }

    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        if ($old_status == $data['status']) return ['statusChanged' => false];

        $statusChanged = false;
        switch ($job->status) {
            case 'timedout':
                $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                break;
            case 'completed':
                $statusChanged = $this->changeCompletedStatus($job, $data);
                break;
            case 'started':
                $statusChanged = $this->changeStartedStatus($job, $data);
                break;
            case 'pending':
                $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                break;
            case 'withdrawafter24':
                $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                break;
            case 'assigned':
                $statusChanged = $this->changeAssignedStatus($job, $data);
                break;
        }

        if ($statusChanged) {
            return ['statusChanged' => true, 'log_data' => ['old_status' => $old_status, 'new_status' => $data['status']]];
        }

        return ['statusChanged' => false];
    }

    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $dataEmail = ['user' => $user, 'job' => $job];

        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $job_data, '*');
            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout' && $data['admin_comments'] == '') return false;
        $job->admin_comments = $data['admin_comments'];
        $job->save();
        return true;
    }

    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') return false;
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $this->completeJob($job, $data);
        }
        $job->save();
        return true;
    }

    private function completeJob($job, $data)
    {
        $user = $job->user()->first();
        if ($data['sesion_time'] == '') return false;
        $interval = $data['sesion_time'];
        $diff = explode(':', $interval);
        $job->end_at = date('Y-m-d H:i:s');
        $job->session_time = $interval;
        $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'faktura'
        ];

        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

        $translator = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
        $email = $translator->user->email;
        $name = $translator->user->name;
        $dataEmail['for_text'] = 'lön';
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
    }

    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $dataEmail = ['user' => $user, 'job' => $job];

        if ($data['status'] == 'assigned' && $changedTranslator) {
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    private function changeWithdrawafter24Status($job, $data)
    {
        if ($data['status'] == 'timedout') {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $this->notifyCustomerAndTranslator($job);
            }
            $job->save();
            return true;
        }
        return false;
    }

    private function notifyCustomerAndTranslator($job)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $dataEmail = ['user' => $user, 'job' => $job];

        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

        $translator = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
        $email = $translator->user->email;
        $name = $translator->user->name;
        $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
    }

    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;
        $log_data = [];

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                $new_translator = $this->createNewTranslator($current_translator, $data, $job);
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                $new_translator = $this->createNewTranslator(null, $data, $job);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
        }

        return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator ?? null, 'log_data' => $log_data];
    }

    private function createNewTranslator($current_translator, $data, $job)
    {
        if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
        $new_translator = $current_translator ? $current_translator->toArray() : [];
        $new_translator['user_id'] = $data['translator'];
        unset($new_translator['id']);
        $new_translator = Translator::create($new_translator);
        if ($current_translator) {
            $current_translator->cancel_at = Carbon::now();
            $current_translator->save();
        }
        return $new_translator;
    }

    private function changeDue($old_due, $new_due)
    {
        if ($old_due == $new_due) return ['dateChanged' => false];

        return [
            'dateChanged' => true,
            'log_data' => [
                'old_due' => $old_due,
                'new_due' => $new_due
            ]
        ];
    }

    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = ['user' => $user, 'job' => $job];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);

        if ($current_translator) {
            $this->notifyTranslator($current_translator, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $this->notifyTranslator($new_translator, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    private function notifyTranslator($translator, $subject, $template, $data)
    {
        $user = $translator->user;
        $email = $user->email;
        $name = $user->name;
        $data['user'] = $user;
        $this->mailer->send($email, $name, $subject, $template, $data);
    }

    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = ['user' => $user, 'job' => $job, 'old_time' => $old_time];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = ['user' => $user, 'job' => $job, 'old_lang' => $old_lang];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }
}