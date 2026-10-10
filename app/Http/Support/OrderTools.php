<?php

namespace App\Http\Support;

use App\Domain\Ordering\CartService;
use App\Models\BulkEntryImport;
use App\Models\Cart;
use App\Models\Company;
use App\Models\SavedList;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * 05.1 §14: the acting company's own imports and saved lists. Anything
 * belonging to another company — or another user's import — is a 404,
 * whatever id was guessed. The basket is the session's company cart.
 */
final class OrderTools
{
    public static function import(Company $company, User $user, string $publicId): BulkEntryImport
    {
        return BulkEntryImport::query()->where('public_id', $publicId)->where('company_id', $company->id)
            ->where('user_id', $user->id)->firstOrFail();
    }

    public static function savedList(Company $company, string $publicId): SavedList
    {
        return SavedList::query()->where('public_id', $publicId)->where('company_id', $company->id)->firstOrFail();
    }

    /** The company basket for this session; never another company's. */
    public static function cart(Request $request, Company $company): Cart
    {
        $owner = (new CartContext)->owner($request, createGuestToken: false);
        abort_if($owner === null || $owner->companyId !== $company->id, 403);

        return (new CartService)->cartFor($owner);
    }
}
