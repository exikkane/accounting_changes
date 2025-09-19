<?php

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use Tygh\Registry;
use Tygh\Enum\SiteArea;
use Tygh\Enum\UserTypes;
use Tygh\Enum\VendorStatuses;
use Tygh\Enum\VendorPayoutTypes;

/**
 * The "login_user_post" hook handler.
 *
 * Actions performed:
 *     - Checks whether a vendor has paid for the plan
 *
 * @param int                   $user_id   User identifier
 * @param int                   $cu_id     Cart user identifier
 * @param array<string, string> $udata     User data
 * @param array<string, string> $auth      Authentication data
 * @param string                $condition String containing SQL-query condition possibly prepended with a logical operator (AND or OR)
 * @param string                $result    Result user login
 *
 * @throws \Tygh\Exceptions\DeveloperException When notification event for receiver and transport was not found.
 *
 * @see \fn_login_user()
 */
function fn_accounting_changes_login_user_post($user_id, $cu_id, array $udata, array $auth, $condition, $result)
{
    $vendor_id = isset($udata['company_id']) ? $udata['company_id'] : 0;

    if (
        $result !== LOGIN_STATUS_OK
        || ($udata['user_type'] !== UserTypes::ADMIN && $udata['user_type'] !== UserTypes::VENDOR)
        || SiteArea::isStorefront(AREA)
        || fn_accounting_changes_is_under_grace_period($vendor_id)
    ) {
        return;
    }

    fn_accounting_changes_is_payment_active($vendor_id);
}

/**
 * Checks whether the vendor has its vendor plan subscription active
 *
 * @param $vendor_id
 * @return false|void
 */
function fn_accounting_changes_is_payment_active($vendor_id)
{
    $subscription_id = db_get_field('
        SELECT vp.subscription_id FROM ?:vendor_payment_profiles vp
        LEFT JOIN ?:users u ON vp.user_id = u.user_id
        WHERE u.user_type = ?s AND u.is_root = ?s AND u.company_id = ?i', 'V', 'Y',
        $vendor_id);

    if (empty($subscription_id)) {
        fn_change_company_status($vendor_id, VendorStatuses::SUSPENDED);
        return false;
    }
    $vendor_plan_id = fn_vendor_plans_get_vendor_plan_by_company_id($vendor_id);
    $subscription_data = fn_accounting_changes_get_subscription_data($subscription_id, $vendor_plan_id->plan_id);

    if (empty($subscription_data) || $subscription_data['status'] !== 'active') {
        fn_change_company_status($vendor_id, VendorStatuses::SUSPENDED);
    }
}

/**
 * Checks whether a vendor under the grace period
 *
 * @param $vendor_id
 * @return bool
 */
function fn_accounting_changes_is_under_grace_period($vendor_id)
{
    if (!$vendor_id) {
        return false;
    }

    $registration_timestamp = db_get_field('SELECT timestamp FROM ?:companies WHERE company_id = ?i', $vendor_id);
    $limit = Registry::get('addons.accounting_changes.registration_date_limit');

    $date = (new DateTime())->setTimestamp((int)$registration_timestamp);
    $now  = new DateTime();

    $diff = $now->diff($date);

    return $diff->days < $limit;
}

/**
 * Checks whether the external subscription is active
 *
 * @param $subscription_id
 * @param $vendor_plan_id
 * @return array
 */
function fn_accounting_changes_get_subscription_data($subscription_id, $vendor_plan_id)
{
    $authenticationmode = Registry::get('addons.wk_authorizenet_vendorplan.authorizenet_mode');
    $authorizenet_api_login_id = Registry::get('addons.wk_authorizenet_vendorplan.authorizenet_api_login_id');
    $authorizenet_transaction_key = Registry::get('addons.wk_authorizenet_vendorplan.authorizenet_transaction_key');

    $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
    $merchantAuthentication->setName($authorizenet_api_login_id);
    $merchantAuthentication->setTransactionKey($authorizenet_transaction_key);

    $apiEnvironment = ($authenticationmode === 'live')
        ? \net\authorize\api\constants\ANetEnvironment::PRODUCTION
        : \net\authorize\api\constants\ANetEnvironment::SANDBOX;

    $subscriptionRequest = new AnetAPI\ARBGetSubscriptionRequest();
    $subscriptionRequest->setmerchantAuthentication($merchantAuthentication);
    $subscriptionRequest->setSubscriptionId($subscription_id);

    $subscriptionController = new AnetController\ARBGetSubscriptionController($subscriptionRequest);
    $subscriptionResponse = $subscriptionController->executeWithApiResponse($apiEnvironment);

    $subscription_details = [];

    if ($subscriptionResponse->getMessages()->getResultCode() == "Ok") {
        $subscription = $subscriptionResponse->getSubscription();
        $get_the_plan_price = db_get_field('SELECT price FROM ?:vendor_plans WHERE plan_id = ?i', $vendor_plan_id);

        $subscription_details = [
            'subscription_id' => $subscription_id,
            'status' => $subscription->getStatus(),
            'start_date' => $subscription->getPaymentSchedule()->getStartDate()->format('Y-m-d'),
            'amount' => $subscription->getAmount(),
            'interval' => $subscription->getPaymentSchedule()->getInterval()->getUnit(),
            'profile_id' => $subscription->getProfile()->getCustomerProfileId(),
            'payment_profile_id' => $subscription->getProfile()->getPaymentProfile()->getCustomerPaymentProfileId(),
            'next_billing_date' => $subscription->getPaymentSchedule()->getStartDate()->format('Y-m-d'),
            'plan_match_status' => ($get_the_plan_price == $subscription->getAmount()) ? "" : 1,
        ];
    }
    return $subscription_details;
}

/**
 * 'update_company' hook handler
 *
 * Suspend the vendor if the vendor plan stays unpaid after the grace period ended
 *
 * @param $company_data
 * @param $company_id
 * @return void
 */
function fn_accounting_changes_update_company($company_data, $company_id)
{
    if (
        isset($company_data['plan_id'])
        && isset($company_data['current_plan'])
        && $company_data['plan_id'] != $company_data['current_plan']
        && $company_data['status'] != VendorStatuses::NEW_ACCOUNT
        && fn_accounting_changes_is_under_grace_period($company_id)
    ) {
        $existing_payout = db_get_field(
            'SELECT payout_id FROM ?:vendor_payouts WHERE company_id = ?i AND plan_id = ?i AND payout_type = ?s',
            $company_id,
            $company_data['current_plan'],
            VendorPayoutTypes::PAYOUT
        );

        if (!$existing_payout) {
            return;
        }
        db_query('DELETE FROM ?:vendor_payouts WHERE payout_id = ?i', $existing_payout);
    }
}
