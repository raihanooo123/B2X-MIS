<?php

namespace App\Policies\Concerns;

/**
 * The role groupings the catalogue Policies are built from — named
 * once so the actual rule ("who can see Brand/Category" vs "who can
 * see Product/Sku/Pack") lives in exactly one place, per doc 02 §14.1's
 * six-value closed role list.
 */
final class CatalogueRoles
{
    /**
     * Brand/Category: merchandising configuration. purchasing, rep and
     * sales_manager can see it; warehouse and accounts get nothing on
     * either.
     *
     * @var list<string>
     */
    public const MERCHANDISING_VIEWERS = ['admin', 'purchasing', 'rep', 'sales_manager'];

    /**
     * Product/Sku/Pack: the same staff plus warehouse and accounts, who
     * both need to see the sellable catalogue (picking, invoicing) but
     * not the brand/category structure behind it.
     *
     * @var list<string>
     */
    public const OPERATIONAL_VIEWERS = ['admin', 'purchasing', 'rep', 'sales_manager', 'warehouse', 'accounts'];

    /**
     * Every catalogue Policy: only admin creates or edits.
     *
     * @var list<string>
     */
    public const MANAGERS = ['admin'];
}
