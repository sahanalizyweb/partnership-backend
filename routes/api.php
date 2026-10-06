<?php

use App\Http\Controllers\Api\AffiliateEnquiryController;
use App\Http\Controllers\Api\AffiliateLinkController;
use App\Http\Controllers\Api\AgreementController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\BusinessSourceController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\CommissionRuleController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentTypeController;
use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\PartnerCategoryController;
use App\Http\Controllers\Api\PartnerController;
use App\Http\Controllers\Api\PartnerDocumentController;
use App\Http\Controllers\Api\PartnerEarningsController;
use App\Http\Controllers\Api\PartnerTypeController;
use App\Http\Controllers\Api\PartnerTypeEnrollmentController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PerformanceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ResellerPaymentController;
use App\Http\Controllers\Api\ResellerProductPriceController;
use App\Http\Controllers\Api\ResellerPurchaseController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettlementController;
use App\Http\Controllers\Api\SupplierPaymentController;
use App\Http\Controllers\Api\SupplierProductController;
use App\Http\Controllers\Api\SupplyController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);

    // Public (no login): the shop reports an affiliate-link visit (?ref=CODE). Throttled per IP.
    Route::match(['get', 'post'], 'public/affiliate-links/{code}/click', [AffiliateLinkController::class, 'trackClick'])
        ->middleware('throttle:60,1');
    // Public, key-verified (X-Website-Key): the lizyweb.in website backend forwards each
    // enquiry sent through an affiliate ?ref= link. Server-to-server only. Throttled per IP.
    Route::post('public/affiliate-enquiries', [AffiliateEnquiryController::class, 'storeFromWebsite'])
        ->middleware('throttle:30,1');
    // Public, HMAC-verified: Lizy Mart (Shopify) order webhooks — create/paid/cancelled.
    Route::post('public/shopify/webhooks/orders', [\App\Http\Controllers\Api\ShopifyWebhookController::class, 'orders']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard.view');

        // Top-bar global search — each result group checks its own .view permission and
        // partner-portal users only get their own records (see SearchController).
        Route::get('search', [SearchController::class, 'index']);

        // Businesses (global master data; only Super Administrator can manage them)
        Route::get('businesses', [BusinessController::class, 'index']);
        Route::get('businesses/{business}', [BusinessController::class, 'show']);
        Route::middleware('role:Super Administrator')->group(function () {
            Route::post('businesses', [BusinessController::class, 'store']);
            Route::put('businesses/{business}', [BusinessController::class, 'update']);
            Route::delete('businesses/{business}', [BusinessController::class, 'destroy']);
        });

        // Partner Types
        Route::get('partner-types', [PartnerTypeController::class, 'index'])->middleware('permission:partner-types.view');
        Route::post('partner-types', [PartnerTypeController::class, 'store'])->middleware('permission:partner-types.create');
        Route::get('partner-types/{partnerType}', [PartnerTypeController::class, 'show'])->middleware('permission:partner-types.view');
        Route::put('partner-types/{partnerType}', [PartnerTypeController::class, 'update'])->middleware('permission:partner-types.edit');
        Route::delete('partner-types/{partnerType}', [PartnerTypeController::class, 'destroy'])->middleware('permission:partner-types.delete');

        // Partner Categories
        Route::get('partner-categories', [PartnerCategoryController::class, 'index'])->middleware('permission:partner-categories.view');
        Route::post('partner-categories', [PartnerCategoryController::class, 'store'])->middleware('permission:partner-categories.create');
        Route::get('partner-categories/{partnerCategory}', [PartnerCategoryController::class, 'show'])->middleware('permission:partner-categories.view');
        Route::put('partner-categories/{partnerCategory}', [PartnerCategoryController::class, 'update'])->middleware('permission:partner-categories.edit');
        Route::delete('partner-categories/{partnerCategory}', [PartnerCategoryController::class, 'destroy'])->middleware('permission:partner-categories.delete');

        // Business Sources (config) — LizyMart, LizyNet, Service, Referral, Affiliate, Sales,
        // Reseller, Other. Reuses the partner-types.* permission module (same admin-config shape).
        Route::get('business-sources', [BusinessSourceController::class, 'index'])->middleware('permission:partner-types.view');
        Route::post('business-sources', [BusinessSourceController::class, 'store'])->middleware('permission:partner-types.create');
        Route::get('business-sources/{businessSource}', [BusinessSourceController::class, 'show'])->middleware('permission:partner-types.view');
        Route::put('business-sources/{businessSource}', [BusinessSourceController::class, 'update'])->middleware('permission:partner-types.edit');
        Route::delete('business-sources/{businessSource}', [BusinessSourceController::class, 'destroy'])->middleware('permission:partner-types.delete');

        // Locations
        Route::get('locations', [LocationController::class, 'index'])->middleware('permission:locations.view');
        Route::post('locations', [LocationController::class, 'store'])->middleware('permission:locations.create');
        Route::get('locations/{location}', [LocationController::class, 'show'])->middleware('permission:locations.view');
        Route::put('locations/{location}', [LocationController::class, 'update'])->middleware('permission:locations.edit');
        Route::delete('locations/{location}', [LocationController::class, 'destroy'])->middleware('permission:locations.delete');

        // Document Types (config) + Partner Documents (transactional)
        Route::get('document-types', [DocumentTypeController::class, 'index'])->middleware('permission:documents.view');
        Route::post('document-types', [DocumentTypeController::class, 'store'])->middleware('permission:documents.create');
        Route::put('document-types/{documentType}', [DocumentTypeController::class, 'update'])->middleware('permission:documents.edit');
        Route::delete('document-types/{documentType}', [DocumentTypeController::class, 'destroy'])->middleware('permission:documents.delete');

        Route::get('partners/{partner}/documents', [PartnerDocumentController::class, 'index'])->middleware('permission:documents.view');
        Route::post('partners/{partner}/documents', [PartnerDocumentController::class, 'store'])->middleware('permission:documents.create');
        Route::post('documents/{document}/verify', [PartnerDocumentController::class, 'verify'])->middleware('permission:documents.approve');
        Route::post('documents/{document}/reject', [PartnerDocumentController::class, 'reject'])->middleware('permission:documents.approve');
        Route::delete('documents/{document}', [PartnerDocumentController::class, 'destroy'])->middleware('permission:documents.delete');

        // Partner Portal self-service profile (resolved from the logged-in user's own
        // partner_id, not a route parameter — no permission middleware needed).
        Route::get('me/partner', [PartnerController::class, 'me']);
        Route::put('me/partner', [PartnerController::class, 'updateMe']);

        // Partner Portal self-service: every partner-type enrollment this login holds, and
        // the combined LizyMart/LizyNet + per-type earnings summary (backend-calculated).
        Route::get('me/enrollments', [PartnerTypeEnrollmentController::class, 'mine']);
        Route::get('partner/earnings/summary', [PartnerEarningsController::class, 'mySummary']);
        Route::get('partner/earnings/transactions', [PartnerEarningsController::class, 'myTransactions']);

        // Partner Portal self-service edit/delete of a Sale/Referral the partner recorded —
        // ownership + editability (still pending, commission not yet actioned) enforced in
        // the controller, same pattern as me/partner above. Deliberately NOT gated by
        // commissions.edit/delete, which also guard the admin commission-adjustment endpoint.
        Route::put('me/sales/{sale}', [SaleController::class, 'updateMine']);
        // Affiliate partner's own hand-entered enquiries — same self-service pattern.
        Route::put('me/affiliate-enquiries/{affiliateEnquiry}', [AffiliateEnquiryController::class, 'updateMine']);
        Route::delete('me/affiliate-enquiries/{affiliateEnquiry}', [AffiliateEnquiryController::class, 'destroyMine']);
        Route::delete('me/sales/{sale}', [SaleController::class, 'destroyMine']);

        // Partner Portal self-service edit/delete of a Supply the supplier logged — same
        // pattern as me/sales above (ownership + editability enforced in the controller).
        Route::put('me/supplies/{supply}', [SupplyController::class, 'updateMine']);
        Route::delete('me/supplies/{supply}', [SupplyController::class, 'destroyMine']);

        // Partner Portal self-service edit/delete of a Reseller Purchase the reseller logged —
        // same pattern as me/supplies above (ownership + editability enforced in the controller).
        Route::put('me/reseller-purchases/{resellerPurchase}', [ResellerPurchaseController::class, 'updateMine']);
        Route::delete('me/reseller-purchases/{resellerPurchase}', [ResellerPurchaseController::class, 'destroyMine']);

        // Partners
        Route::get('partners', [PartnerController::class, 'index'])->middleware('permission:partners.view');
        Route::post('partners', [PartnerController::class, 'store'])->middleware('permission:partners.create');
        Route::get('partners/{partner}', [PartnerController::class, 'show'])->middleware('permission:partners.view');
        Route::put('partners/{partner}', [PartnerController::class, 'update'])->middleware('permission:partners.edit');
        Route::delete('partners/{partner}', [PartnerController::class, 'destroy'])->middleware('permission:partners.delete');
        Route::post('partners/{partner}/verify', [PartnerController::class, 'verify'])->middleware('permission:partners.approve');
        Route::post('partners/{partner}/suspend', [PartnerController::class, 'suspend'])->middleware('permission:partners.approve');
        Route::post('partners/{partner}/reactivate', [PartnerController::class, 'reactivate'])->middleware('permission:partners.approve');

        // Partner Type Enrollments — one Partner Profile can hold many simultaneously.
        Route::get('partners/{partner}/enrollments', [PartnerTypeEnrollmentController::class, 'index'])->middleware('permission:partners.view');
        Route::post('partners/{partner}/enrollments', [PartnerTypeEnrollmentController::class, 'store'])->middleware('permission:partners.edit');
        Route::put('partners/{partner}/enrollments/{enrollment}', [PartnerTypeEnrollmentController::class, 'update'])->middleware('permission:partners.edit');
        Route::delete('partners/{partner}/enrollments/{enrollment}', [PartnerTypeEnrollmentController::class, 'destroy'])->middleware('permission:partners.delete');
        Route::post('partners/{partner}/enrollments/{enrollment}/activate', [PartnerTypeEnrollmentController::class, 'activate'])->middleware('permission:partners.approve');
        Route::post('partners/{partner}/enrollments/{enrollment}/suspend', [PartnerTypeEnrollmentController::class, 'suspend'])->middleware('permission:partners.approve');
        Route::post('partners/{partner}/enrollments/{enrollment}/approve-brand-mode', [PartnerTypeEnrollmentController::class, 'approveBrandMode'])->middleware('permission:partners.approve');

        // Total Partner Earnings — admin mirror of the partner-portal self-service view.
        Route::get('partners/{partner}/earnings', [PartnerEarningsController::class, 'summary'])->middleware('permission:partners.view');
        Route::get('partners/{partner}/earnings/transactions', [PartnerEarningsController::class, 'transactions'])->middleware('permission:partners.view');

        // Partner portal login account, managed from the Partner Details page (Super Admin only).
        Route::post('partners/{partner}/portal-account', [PartnerController::class, 'createPortalAccount'])->middleware('permission:users.edit');
        Route::put('partners/{partner}/portal-account/email', [PartnerController::class, 'updatePortalEmail'])->middleware('permission:users.edit');
        Route::post('partners/{partner}/portal-account/reset-password', [PartnerController::class, 'resetPortalPassword'])->middleware('permission:users.edit');

        // Agreements
        Route::get('agreements', [AgreementController::class, 'index'])->middleware('permission:agreements.view');
        Route::post('agreements', [AgreementController::class, 'store'])->middleware('permission:agreements.create');
        Route::get('agreements/{agreement}', [AgreementController::class, 'show'])->middleware('permission:agreements.view');
        Route::put('agreements/{agreement}', [AgreementController::class, 'update'])->middleware('permission:agreements.edit');
        Route::delete('agreements/{agreement}', [AgreementController::class, 'destroy'])->middleware('permission:agreements.delete');
        Route::post('agreements/{agreement}/activate', [AgreementController::class, 'activate'])->middleware('permission:agreements.approve');
        Route::post('agreements/{agreement}/terminate', [AgreementController::class, 'terminate'])->middleware('permission:agreements.approve');
        Route::post('agreements/{agreement}/renew', [AgreementController::class, 'renew'])->middleware('permission:agreements.edit');

        // Assignments
        Route::get('assignments', [AssignmentController::class, 'index'])->middleware('permission:assignments.view');
        Route::post('assignments', [AssignmentController::class, 'store'])->middleware('permission:assignments.create');
        Route::get('assignments/suggest-partners', [AssignmentController::class, 'suggestPartners'])->middleware('permission:assignments.view');
        Route::get('assignments/{assignment}', [AssignmentController::class, 'show'])->middleware('permission:assignments.view');
        Route::put('assignments/{assignment}', [AssignmentController::class, 'update'])->middleware('permission:assignments.edit');
        Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy'])->middleware('permission:assignments.delete');
        Route::post('assignments/{assignment}/assign', [AssignmentController::class, 'assign'])->middleware('permission:assignments.edit');
        Route::post('assignments/{assignment}/accept', [AssignmentController::class, 'accept'])->middleware('permission:assignments.edit');
        Route::post('assignments/{assignment}/reject', [AssignmentController::class, 'reject'])->middleware('permission:assignments.edit');
        Route::post('assignments/{assignment}/start', [AssignmentController::class, 'start'])->middleware('permission:assignments.edit');
        Route::post('assignments/{assignment}/complete', [AssignmentController::class, 'complete'])->middleware('permission:assignments.edit');
        Route::post('assignments/{assignment}/cancel', [AssignmentController::class, 'cancel'])->middleware('permission:assignments.edit');
        Route::post('assignments/{assignment}/reassign', [AssignmentController::class, 'reassign'])->middleware('permission:assignments.edit');
        Route::post('assignments/{assignment}/pick-up', [AssignmentController::class, 'pickUp'])->middleware('permission:assignments.edit');
        Route::post('assignments/{assignment}/out-for-delivery', [AssignmentController::class, 'outForDelivery'])->middleware('permission:assignments.edit');
        Route::post('assignments/{assignment}/deliver', [AssignmentController::class, 'deliver'])->middleware('permission:assignments.edit');
        Route::get('assignments/{assignment}/delivery-proof', [AssignmentController::class, 'deliveryProof'])->middleware('permission:assignments.view');
        Route::post('assignments/{assignment}/fail', [AssignmentController::class, 'markFailed'])->middleware('permission:assignments.edit');

        // Products / Services — admin-defined catalog (amount + fixed standard commission %)
        // that Sales/Referrals are recorded against. Read access is shared with partner-portal
        // users (via products.view granted to the Partner role) so they can pick one when
        // recording their own sale.
        Route::get('products', [ProductController::class, 'index'])->middleware('permission:products.view');
        Route::get('products/shop-catalog', [ProductController::class, 'shopCatalog'])->middleware('permission:products.view');
        Route::get('products/link-settings', [ProductController::class, 'linkSettings'])->middleware('permission:products.view');
        Route::post('products', [ProductController::class, 'store'])->middleware('permission:products.create');
        Route::get('products/{product}', [ProductController::class, 'show'])->middleware('permission:products.view');
        Route::put('products/{product}', [ProductController::class, 'update'])->middleware('permission:products.edit');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('permission:products.delete');

        // Sales — the primary commission-earning activity; recording one generates its Commission.
        Route::get('sales', [SaleController::class, 'index'])->middleware('permission:commissions.view');
        Route::post('sales', [SaleController::class, 'store'])->middleware('permission:commissions.create');
        Route::get('sales/{sale}', [SaleController::class, 'show'])->middleware('permission:commissions.view');
        Route::put('sales/{sale}', [SaleController::class, 'update'])->middleware('permission:commissions.edit');
        Route::delete('sales/{sale}', [SaleController::class, 'destroy'])->middleware('permission:commissions.delete');

        // Affiliate Links — Method A attribution (requirement #8). Reuses the commissions.*
        // permission module, same as Sales already do.
        Route::get('affiliate-links', [AffiliateLinkController::class, 'index'])->middleware('permission:commissions.view');
        Route::post('affiliate-links', [AffiliateLinkController::class, 'store'])->middleware('permission:commissions.create');
        Route::get('affiliate-links/preview', [AffiliateLinkController::class, 'preview'])->middleware('permission:commissions.create');
        Route::get('affiliate-links/resolve/{code}', [AffiliateLinkController::class, 'resolve'])->middleware('permission:commissions.view');
        Route::get('affiliate-links/{affiliateLink}', [AffiliateLinkController::class, 'show'])->middleware('permission:commissions.view');
        Route::put('affiliate-links/{affiliateLink}', [AffiliateLinkController::class, 'update'])->middleware('permission:commissions.edit');
        Route::post('affiliate-links/{affiliateLink}/count', [AffiliateLinkController::class, 'adjustCount'])->middleware('permission:commissions.edit');
        Route::delete('affiliate-links/{affiliateLink}', [AffiliateLinkController::class, 'destroy'])->middleware('permission:commissions.delete');

        // Affiliate Enquiries — website (?ref=) and hand-entered enquiries, converted into Sales.
        // Same commissions.* module as Affiliate Links; partner users only see their own.
        Route::get('affiliate-enquiries', [AffiliateEnquiryController::class, 'index'])->middleware('permission:commissions.view');
        Route::post('affiliate-enquiries', [AffiliateEnquiryController::class, 'store'])->middleware('permission:commissions.create');
        Route::get('affiliate-enquiries/{affiliateEnquiry}', [AffiliateEnquiryController::class, 'show'])->middleware('permission:commissions.view');
        Route::put('affiliate-enquiries/{affiliateEnquiry}', [AffiliateEnquiryController::class, 'update'])->middleware('permission:commissions.edit');
        Route::delete('affiliate-enquiries/{affiliateEnquiry}', [AffiliateEnquiryController::class, 'destroy'])->middleware('permission:commissions.delete');

        // Supplier Partner — the Supplier -> Our Company flow. Deliberately separate from
        // Sales/Commissions/Payments above: admin-only to create/edit/delete (the supplier
        // just views their own read-only history/balance), self-contained tables, own
        // permission module so granting a Supplier Partner read access here can't also grant
        // them anything on the Commission/Payment endpoints used by other partner types.
        Route::get('supplier-products', [SupplierProductController::class, 'index'])->middleware('permission:supplies.view');
        Route::post('supplier-products', [SupplierProductController::class, 'store'])->middleware('permission:supplies.create');
        Route::get('supplier-products/{supplierProduct}', [SupplierProductController::class, 'show'])->middleware('permission:supplies.view');
        Route::put('supplier-products/{supplierProduct}', [SupplierProductController::class, 'update'])->middleware('permission:supplies.edit');
        Route::delete('supplier-products/{supplierProduct}', [SupplierProductController::class, 'destroy'])->middleware('permission:supplies.delete');

        Route::get('supplies', [SupplyController::class, 'index'])->middleware('permission:supplies.view');
        Route::post('supplies', [SupplyController::class, 'store'])->middleware('permission:supplies.create');
        Route::get('supplies/{supply}', [SupplyController::class, 'show'])->middleware('permission:supplies.view');
        Route::put('supplies/{supply}', [SupplyController::class, 'update'])->middleware('permission:supplies.edit');
        Route::delete('supplies/{supply}', [SupplyController::class, 'destroy'])->middleware('permission:supplies.delete');

        Route::get('supplier-payments', [SupplierPaymentController::class, 'index'])->middleware('permission:supplies.view');
        Route::post('supplier-payments', [SupplierPaymentController::class, 'store'])->middleware('permission:supplies.create');
        Route::get('supplier-payments/{supplierPayment}', [SupplierPaymentController::class, 'show'])->middleware('permission:supplies.view');
        Route::delete('supplier-payments/{supplierPayment}', [SupplierPaymentController::class, 'destroy'])->middleware('permission:supplies.delete');

        // Reseller Partner — the Lizyweb -> Reseller -> Customer flow, the mirror of the
        // Supplier block above (there we owe the partner; here the partner owes us). Same
        // shape: admin-only to create/edit/delete of others' records, own permission module,
        // self-contained tables.
        Route::get('reseller-purchases', [ResellerPurchaseController::class, 'index'])->middleware('permission:resellers.view');
        Route::post('reseller-purchases', [ResellerPurchaseController::class, 'store'])->middleware('permission:resellers.create');
        Route::get('reseller-purchases/{resellerPurchase}', [ResellerPurchaseController::class, 'show'])->middleware('permission:resellers.view');
        Route::put('reseller-purchases/{resellerPurchase}', [ResellerPurchaseController::class, 'update'])->middleware('permission:resellers.edit');
        Route::delete('reseller-purchases/{resellerPurchase}', [ResellerPurchaseController::class, 'destroy'])->middleware('permission:resellers.delete');

        Route::get('reseller-payments', [ResellerPaymentController::class, 'index'])->middleware('permission:resellers.view');
        Route::post('reseller-payments', [ResellerPaymentController::class, 'store'])->middleware('permission:resellers.create');
        Route::get('reseller-payments/{resellerPayment}', [ResellerPaymentController::class, 'show'])->middleware('permission:resellers.view');
        Route::delete('reseller-payments/{resellerPayment}', [ResellerPaymentController::class, 'destroy'])->middleware('permission:resellers.delete');

        // Reseller product pricing (requirement #19: purchase price / recommended selling price per enrollment+product).
        Route::get('reseller-product-prices', [ResellerProductPriceController::class, 'index'])->middleware('permission:resellers.view');
        Route::post('reseller-product-prices', [ResellerProductPriceController::class, 'store'])->middleware('permission:resellers.edit');
        Route::put('reseller-product-prices/{resellerProductPrice}', [ResellerProductPriceController::class, 'update'])->middleware('permission:resellers.edit');
        Route::delete('reseller-product-prices/{resellerProductPrice}', [ResellerProductPriceController::class, 'destroy'])->middleware('permission:resellers.delete');

        // Commission rules + commissions
        Route::apiResource('commission-rules', CommissionRuleController::class)->except(['update'])->middleware('permission:commissions.view');
        Route::put('commission-rules/{commissionRule}', [CommissionRuleController::class, 'update'])->middleware('permission:commissions.edit');

        Route::get('commissions', [CommissionController::class, 'index'])->middleware('permission:commissions.view');
        Route::get('commissions/{commission}', [CommissionController::class, 'show'])->middleware('permission:commissions.view');
        Route::put('commissions/{commission}', [CommissionController::class, 'update'])->middleware('permission:commissions.edit');
        Route::post('commissions/{commission}/approve', [CommissionController::class, 'approve'])->middleware('permission:commissions.approve');
        Route::post('commissions/{commission}/record-payment', [CommissionController::class, 'recordPayment'])->middleware('permission:payments.create');
        Route::post('commissions/{commission}/reject', [CommissionController::class, 'reject'])->middleware('permission:commissions.approve');

        // Payments
        Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:payments.view');
        Route::post('payments', [PaymentController::class, 'store'])->middleware('permission:payments.create');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:payments.view');
        Route::post('payments/{payment}/complete', [PaymentController::class, 'complete'])->middleware('permission:payments.approve');
        Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel'])->middleware('permission:payments.edit');

        // Ledger (read-only)
        Route::get('ledger', [LedgerController::class, 'index'])->middleware('permission:ledger.view');
        Route::get('partners/{partner}/statement', [LedgerController::class, 'statement'])->middleware('permission:ledger.view');

        // Settlements
        Route::get('settlements', [SettlementController::class, 'index'])->middleware('permission:settlements.view');
        Route::post('settlements', [SettlementController::class, 'store'])->middleware('permission:settlements.create');
        Route::get('settlements/{settlement}', [SettlementController::class, 'show'])->middleware('permission:settlements.view');
        Route::post('settlements/{settlement}/submit', [SettlementController::class, 'submitForApproval'])->middleware('permission:settlements.edit');
        Route::post('settlements/{settlement}/approve', [SettlementController::class, 'approve'])->middleware('permission:settlements.approve');
        Route::post('settlements/{settlement}/reject', [SettlementController::class, 'reject'])->middleware('permission:settlements.approve');

        // Performance
        Route::get('performance', [PerformanceController::class, 'index'])->middleware('permission:performance.view');
        Route::post('performance', [PerformanceController::class, 'store'])->middleware('permission:performance.edit');
        Route::get('performance/{performanceMetric}', [PerformanceController::class, 'show'])->middleware('permission:performance.view');

        // Users & Roles
        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
        Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('permission:users.edit');
        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:users.view');
        Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:users.view');

        // Reports
        Route::get('reports/{type}', [ReportController::class, 'show'])->middleware('permission:reports.view');
        Route::get('reports/{type}/export', [ReportController::class, 'export'])->middleware('permission:reports.view');
        Route::get('partners/{partner}/report', [ReportController::class, 'partnerReport'])->middleware('permission:reports.view');
        Route::get('partners/{partner}/report/export', [ReportController::class, 'partnerExport'])->middleware('permission:reports.view');

        // Notifications (dashboard alerts panel)
        Route::get('notifications', function (Request $request) {
            return $request->user()->notifications()->latest()->limit(50)->get();
        });
        Route::post('notifications/{id}/read', function (Request $request, string $id) {
            $request->user()->notifications()->where('id', $id)->first()?->markAsRead();

            return response()->json(['message' => 'ok']);
        });
    });
});
