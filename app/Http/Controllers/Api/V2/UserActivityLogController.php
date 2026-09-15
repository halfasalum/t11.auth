<?php

namespace App\Http\Controllers\Api\V2;

use App\Models\User;
use App\Models\UserLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserActivityLogController extends BaseController
{
    /**
     * Every activity entry for the acting user's company.
     *
     * GET /api/v2/activity-logs
     *
     * Supported query params:
     *   per_page      int    rows per page (default 20, max 100)
     *   page          int    page number
     *   user_id       int    restrict to a single user
     *   action        string partial match on the action name
     *   method        string exact HTTP method (GET, POST, ...)
     *   route         string partial match on the route name / path
     *   status_code   int    exact response status code
     *   search        string partial match across action, route, ip and user agent
     *   from_date     date   created_at >= this date (inclusive)
     *   to_date       date   created_at <= this date (inclusive)
     */
    public function index(Request $request)
    {
        try {
            $filters = $this->validateFilters($request);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $logs = $this->baseQuery($request, $filters)
            ->paginate($this->perPage($request));

        return $this->successResponse($this->paginateResponse($logs));
    }

    /**
     * Activity entries for one specific user (scoped to the acting company).
     *
     * GET /api/v2/activity-logs/user/{userId}
     *
     * Accepts the same query params as index() except `user_id`, which is taken
     * from the route.
     */
    public function forUser(Request $request, int $userId)
    {
        $user = User::where('user_company', $this->getCompanyId())
            ->select('id', 'name', 'email', 'first_name', 'last_name', 'phone')
            ->find($userId);

        if (! $user) {
            return $this->errorResponse('User not found for this company', 404);
        }

        try {
            $filters = $this->validateFilters($request);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }

        $filters['user_id'] = $user->id;

        $logs = $this->baseQuery($request, $filters)
            ->paginate($this->perPage($request));

        $payload = $this->paginateResponse($logs);
        $payload['user'] = $user;

        return $this->successResponse($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'user_id'     => 'nullable|integer',
            'action'      => 'nullable|string|max:255',
            'method'      => 'nullable|string|max:10',
            'route'       => 'nullable|string|max:255',
            'status_code' => 'nullable|integer',
            'search'      => 'nullable|string|max:255',
            'from_date'   => 'nullable|date',
            'to_date'     => 'nullable|date',
            'per_page'    => 'nullable|integer|min:1|max:100',
            'page'        => 'nullable|integer|min:1',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(Request $request, array $filters): Builder
    {
        $query = UserLog::query()
            ->where('company', $this->getCompanyId())
            ->with(['user:id,name,email,first_name,last_name,phone']);

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', 'like', '%' . $filters['action'] . '%');
        }

        if (! empty($filters['method'])) {
            $query->where('method', strtoupper($filters['method']));
        }

        if (! empty($filters['route'])) {
            $query->where('route', 'like', '%' . $filters['route'] . '%');
        }

        if (isset($filters['status_code'])) {
            $query->where('status_code', $filters['status_code']);
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhere('route', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('user_agent', 'like', "%{$search}%");
            });
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->input('per_page', 20)));
    }
}
