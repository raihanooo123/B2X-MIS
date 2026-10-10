<?php

namespace App\Http\Controllers\Api\V1\Trade;

use App\Domain\Identity\CompanyMemberService;
use App\Domain\Identity\CompanyMemberSettings;
use App\Domain\Identity\CompanyUserDirectory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Credit\UpdateCompanyUserRequest;
use App\Http\Support\TradeContext;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * PATCH /api/v1/company-users/{user_id} (05.2 §18.3): an owner changes a
 * member's role, per-order limit and approval flag in the acting company.
 * The member is addressed by their user ULID; the company comes from the
 * session (06 §18). CompanyMemberService authorises and audits under its
 * locks, and keeps the last active owner.
 */
class CompanyUserController extends Controller
{
    public function update(UpdateCompanyUserRequest $request, string $userId, CompanyMemberService $service): JsonResponse
    {
        [$actor, $company] = TradeContext::resolve($request);
        $member = User::query()->where('public_id', $userId)->firstOrFail();
        $service->update($company, $member, $actor, CompanyMemberSettings::from($request->validated()));

        foreach (CompanyUserDirectory::management($actor, $company)['members'] ?? [] as $row) {
            if ($row['id'] === $member->public_id) {
                return response()->json(['data' => $row]);
            }
        }

        abort(404);
    }
}
