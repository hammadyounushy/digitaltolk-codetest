<?php

namespace DTApi\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\CreateBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends BaseController
{

    /**
     * @var BookingRepository
     */
    protected $repository;
    protected $userService;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository, \UserService $userService)
    {
        $this->repository = $bookingRepository;
        $this->userService = $userService;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $userId = $request->get('user_id');
        $userType = $request->__authenticatedUser->user_type;

        if ($userId) {
            $response = $this->repository->getUsersJobs($userId);
        } elseif ($this->userService->isUserAnAdminOrSuperAdmin($userType)) {
            $response = $this->repository->getAll($request);
        }

        return $this->sendResponse($response->toArray(), 'Data retrieved successfully');
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);
        return $this->sendResponse($job, 'Data retrieved successfully');
    }

    /**
     * @param CreateBookingRequest $request
     * @return mixed
     */
    public function store(CreateBookingRequest $request)
    {
        $data = $request->all();
        $response = $this->repository->store($request->__authenticatedUser, $data);
        return $this->sendResponse($response, 'Data saved successfully');

    }

    /**
     * @param $id
     * @param UpdateBookingRequest $request
     * @return mixed
     */
    public function update($id, UpdateBookingRequest $request)
    {
        $data = $request->except(['_token', 'submit']);
        $response = $this->repository->updateJob($id, $data, $request->__authenticatedUser);

        return $this->sendResponse($response, 'Data updated successfully');
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request)
    {
        $adminSenderEmail = config('app.adminemail');
        $data = $request->all();

        $response = $this->repository->storeJobEmail($data);

        return $this->sendResponse($response, 'Email send successfully');
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        $userId = $request->get('user_id');
        if($userId) {
            $response = $this->repository->getUsersJobsHistory($userId, $request);
            return $this->sendResponse($response, 'Data retrieved successfully');
        }

        return null;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        $response = $this->repository->acceptJob($data, $user);

        return $this->sendResponse($response, 'Job accepted successfully');
    }

    public function acceptJobWithId(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->acceptJob($data, $request->__authenticatedUser);

        return $this->sendResponse($response, 'Job accepted successfully');
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->cancelJobAjax($data, $request->__authenticatedUser);

        return $this->sendResponse($response, 'Job cancelled successfully');
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->endJob($data);

        return $this->sendResponse($response);

    }

    public function customerNotCall(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->customerNotCall($data);

        return $this->sendResponse($response);

    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $response = $this->repository->getPotentialJobs($request->__authenticatedUser);
        return $this->sendResponse($response);
    }

    public function distanceFeed(Request $request)
    {
        $data = $request->all();
        $jobId = $data['jobid'] ?? null;

        $distance = $data['distance'] ?? '';
        $time = $data['time'] ?? '';
        $session = $data['session_time'] ?? '';
        $flagged = $data['flagged'] == 'true' ? 'yes' : 'no';
        $manuallyHandled = $data['manually_handled'] == 'true' ? 'yes' : 'no';
        $byAdmin = $data['by_admin'] == 'true' ? 'yes' : 'no';
        $adminComment = $data['admincomment'] ?? '';

        if ($flagged == 'yes' && empty($adminComment)) {
            return $this->sendResponse([], "Please, add comment", 400);
        }

        if ($time || $distance) {
            // Queries should move to respective repositories and just call the repository function
            Distance::where('job_id', $jobId)->update(['distance' => $distance, 'time' => $time]);
        }

        if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {
            // Queries should move to respective repositories and just call the repository function
            Job::where('id', $jobId)->update([
                'admin_comments' => $adminComment,
                'flagged' => $flagged,
                'session_time' => $session,
                'manually_handled' => $manuallyHandled,
                'by_admin' => $byAdmin
            ]);
        }

        return $this->sendResponse([], 'Record updated!');
    }

    public function reopen(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->reopen($data);

        return $this->sendResponse($response, 'Data retrieved successfully');
    }

    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);
        $this->repository->sendNotificationTranslator($job, $job_data, '*');

        return $this->sendResponse([], 'Push sent');
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return $this->sendResponse([], 'SMS sent');
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), $e->getCode());
        }
    }

}