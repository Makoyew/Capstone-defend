<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use App\Models\Department;
use Illuminate\Support\Facades\Session;
use App\Notifications\LeaveRequestAccepted;
use Illuminate\Support\Facades\Notification;
use App\Notifications\LeaveRequestRejected;
use App\Notifications\LeaveRequestCreated;
use App\Notifications\SupervisorApprovedLeaveRequest;
use App\Notifications\LeaveRequestEndedNotification;
use Illuminate\Support\Facades\Storage;
use App\Events\UserLog;
use App\Listeners\LogListener;
use Barryvdh\DomPDF\Facade as PDF;


class LeaveRequestController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $leaveRequests = LeaveRequest::where(function ($query) use ($user) {
            if ($user->role === 'supervisor') {
                $query->whereIn('status', ['pending_supervisor', 'recommend_for_approval', 'rejected', 'approved', 'ended']);
            } elseif ($user->role === 'admin') {
                $query->whereIn('status', ['recommend_for_approval', 'approved', 'rejected', 'ended']);
            }
        })
        ->orderBy('created_at', 'desc')
        ->get();

        foreach ($leaveRequests as $leaveRequest) {
            if ($leaveRequest->status === 'approved' && now() > $leaveRequest->end_date && $leaveRequest->status !== 'ended') {
                $leaveRequest->update(['status' => 'ended']);

                $leaveRequest->user->notify(new LeaveRequestEndedNotification($leaveRequest));
            }
        }

        $leaveRequests = LeaveRequest::orderBy('created_at', 'desc')->paginate(5);

        return view('leave_requests.index', compact('leaveRequests'));
    }

    public function create()
    {
        return view('leave_requests.create');
    }

    public function store(Request $request, LeaveRequest $leaveRequest)
    {

        $user = auth()->user();

        $pendingRequest = $user->leaveRequests()->whereIn('status', ['pending_supervisor', 'recommend_for_approval'])->exists();

        if ($pendingRequest) {
            return redirect()->route('dashboard')->with('error', 'You have a pending or approved leave request. You cannot submit another one until it is resolved.');
        }

        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'reason' => 'required|array',
            'other_reason' => 'required|string|max:255',
            'leave_type' => 'required|string|max:255',
            'educational_reason' => 'required|string|max:255',
        ]);

        $reason = implode(', ', $request->input('reason'));


        $educationalReason = $request->input('educational_reason');

        if ($educationalReason === 'other') {
            $request->validate([
                'other_educational_reason' => 'required|string|max:255',
            ]);
            $educationalReason = $request->input('other_educational_reason');
        }

        $leaveType = $request->input('leave_type');
    if ($leaveType === 'other') {
        $leaveType = $request->input('other_leave_type');
    }

        $startDate = new \DateTime($request->input('start_date'));
        $endDate = new \DateTime($request->input('end_date'));
        $interval = $startDate->diff($endDate);
        $number_of_days = $interval->format('%a') + 1;


        LeaveRequest::create([
            'user_id' => $user->id,
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'reason' => $reason,
            'educational_reason' => $educationalReason,
            'other_reason' => $request->input('other_reason'),
            'status' => 'pending_supervisor',
            'leave_type' => $leaveType,
            'number_of_days' => $number_of_days,
        ]);

        $department = $user->department;

        if ($department) {
            $supervisor = User::where('role', 'supervisor')
                ->where('department_id', $department->id)
                ->first();

            if ($supervisor) {
                $supervisor->notify(new LeaveRequestCreated($leaveRequest, $user));
            }
        }

        $log_entry = $user->first_name . $user->surname . $user->role . " added a new leave request " . $leaveRequest->name;
        event(new UserLog($log_entry));

        return redirect()->route('dashboard')->with('success', 'Leave request submitted successfully.');
    }

    public function show(LeaveRequest $leaveRequest)
    {
        $leaveRequests = LeaveRequest::all();

        if ($leaveRequest->status === 'pending_supervisor' && auth()->user()->role === 'supervisor') {
            return view('leave_requests.show', compact('leaveRequest', 'leaveRequests'));
        } elseif ($leaveRequest->status === 'recommend_for_approval' && auth()->user()->role === 'admin') {
            return view('leave_requests.show', compact('leaveRequest', 'leaveRequests'));
        } else {
            return view('leave_requests.show', compact('leaveRequest', 'leaveRequests'));
        }
    }

    public function accept(Request $request, LeaveRequest $leaveRequest)
    {
        $approvalType = $request->input('approval_type');

            if ($approvalType === 'supervisor') {
                if ($leaveRequest->status === 'pending_supervisor') {
                    $leaveRequest->update([
                        'status' => 'recommend_for_approval',
                        'supervisor_approval' => true,
                    ]);

                    // Notify the user about supervisor's approval
                    $admin = User::where('role', 'admin')->first();
                    if ($admin) {
                        $admin->notify(new SupervisorApprovedLeaveRequest($leaveRequest, $admin));
                    }

                    return redirect()->route('leave-requests.show', $leaveRequest)->with('success', 'Supervisor approved the leave request.');
                }
            } elseif ($approvalType === 'admin') {
                // Check if the leave request status is recommend_for_approval before admin's approval
                if ($leaveRequest->status === 'recommend_for_approval') {
                    $leaveRequest->update([
                        'status' => 'approved', // Final approval
                        'admin_approval' => true, // Mark as admin approved
                    ]);

                    // Notify the user about admin's approval
                    $leaveRequest->user->notify(new LeaveRequestAccepted($leaveRequest));

                    return redirect()->route('leave-requests.index')->with('success', 'Leave request approved.');
                }
            }

            return back()->with('error', 'Leave request cannot be approved.');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest)
    {
        // Check if the leave request status is pending before rejecting
        $rejectionType = $request->input('rejection_type');
        $rejectedReason = $request->input('rejected_reason');

        if ($rejectionType === 'supervisor') {
            // Check if the leave request status is pending_supervisor before supervisor's rejection
            if ($leaveRequest->status === 'pending_supervisor') {
                $leaveRequest->update([
                    'status' => 'rejected', // Mark as rejected
                    'supervisor_approval' => false, // Mark as supervisor rejection
                    'rejection_reason' => $rejectedReason,
                ]);

                // Notify the user about supervisor's rejection
                $leaveRequest->user->notify(new LeaveRequestRejected($leaveRequest, $rejectedReason));

                return redirect()->route('leave-requests.show', $leaveRequest)->with('success', 'Supervisor rejected the leave request.');
            }
        } elseif ($rejectionType === 'admin') {
            // Check if the leave request status is recommend_for_approval before admin's rejection
            if ($leaveRequest->status === 'recommend_for_approval') {
                $leaveRequest->update([
                    'status' => 'rejected', // Mark as rejected
                    'admin_approval' => false, // Mark as admin rejection
                    'rejection_reason' => $rejectedReason,
                ]);

                // Notify the user about admin's rejection
                $leaveRequest->user->notify(new LeaveRequestRejected($leaveRequest, $rejectedReason));

                return redirect()->route('leave-requests.show', $leaveRequest)->with('success', 'Admin rejected the leave request.');
            }
        }

        return back()->with('error', 'Leave request cannot be rejected.');
    }



    public function destroy(LeaveRequest $leaveRequest)
    {
        // Check if the leave request belongs to the authenticated user


        // Delete the leave request
        $leaveRequest->delete();

        return redirect()->route('leave-requests.index')->with('success', 'Leave request deleted successfully.');
    }

    public function filtered($status)
    {
        // Query leave requests based on the status
        $query = LeaveRequest::query();

        if ($status === 'all') {
            // No specific status, retrieve all requests
        } elseif ($status === 'pending') {
            $query->whereIn('status', ['pending_supervisor', 'recommend_for_approval']);
        } else {
            $query->where('status', $status);
        }

        $leaveRequests = $query->paginate(10); // Adjust the number of items per page as needed.

        return view('leave_requests.index', compact('leaveRequests'));
    }

    public function filterByMonth(Request $request, $month)
    {
        $leaveRequests = LeaveRequest::whereMonth('start_date', $month)
            ->paginate(10);

        return view('leave_requests.index', [
            'leaveRequests' => $leaveRequests,
        ]);
    }

    public function filterByMonthRecords(Request $request, $user, $month)
    {
        // Assuming you have a method to fetch the user, replace this with your logic
        $user = User::find($user);

        $leaveRequests = LeaveRequest::where('user_id', $user->id)
            ->whereMonth('start_date', $month)
            ->paginate(10);

            return view('users.records', [
                'user' => $user,
                'leaveRequests' => $leaveRequests,
                'filterLeaveType' => $request->input('leaveTypeFilter', 'all'), // Default to 'all' if not provided
            ]);
    }


    public function showUserLeaveRequests(User $user, Request $request)
{
    $leaveRequests = $user->leaveRequests;

    // Get the selected leave type from the request, default to 'all'
    $filterLeaveType = $request->input('leaveTypeFilter', 'all');

    $filteredLeaveRequests = LeaveRequest::where('user_id', $user->id)
        ->filterByLeaveType($filterLeaveType)
        ->get();

    return view('users.records', compact('user', 'leaveRequests', 'filterLeaveType', 'filteredLeaveRequests'));
}
}
