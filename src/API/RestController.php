<?php

namespace Lilleprinsen\Cargonizer\API;

use Lilleprinsen\Cargonizer\Shipping\ShippingMethodRegistry;
use WP_REST_Request;

final class RestController
{
    private ShippingMethodRegistry $shippingRegistry;

    public function __construct(ShippingMethodRegistry $shippingRegistry)
    {
        $this->shippingRegistry = $shippingRegistry;
    }

    public function registerRoutes(): void
    {
        register_rest_route('lp-cargonizer/v1', '/shipping-methods', [
            'methods' => 'GET',
            'permission_callback' => static fn (): bool => current_user_can('manage_woocommerce'),
            'callback' => [$this, 'listShippingMethods'],
        ]);

        register_rest_route('lp-cargonizer/v1', '/bulk-estimates', [
            'methods' => 'POST',
            'permission_callback' => static fn (): bool => current_user_can('manage_woocommerce'),
            'callback' => [$this, 'queueBulkEstimates'],
        ]);

        register_rest_route('lp-cargonizer/v1', '/bulk-estimates/(?P<job_id>[a-zA-Z0-9\-]+)', [
            'methods' => 'GET',
            'permission_callback' => static fn (): bool => current_user_can('manage_woocommerce'),
            'callback' => [$this, 'getBulkEstimateResults'],
        ]);
    }

    public function listShippingMethods(WP_REST_Request $request): array
    {
        unset($request);

        return [
            'methods' => $this->shippingRegistry->all(),
        ];
    }

    public function queueBulkEstimates(WP_REST_Request $request): array
    {
        $jobs = $request->get_param('jobs');
        $jobs = is_array($jobs) ? $jobs : [];

        $jobId = $this->shippingRegistry->enqueueBulkEstimate($jobs);
        if ($jobId === null) {
            return [
                'queued' => false,
                'message' => __('Action Scheduler is unavailable. Bulk estimate queueing skipped.', 'lp-cargonizer'),
            ];
        }

        return [
            'queued' => true,
            'job_id' => $jobId,
        ];
    }

    public function getBulkEstimateResults(WP_REST_Request $request): array
    {
        $jobId = sanitize_text_field((string) $request->get_param('job_id'));
        if ($jobId === '') {
            return ['ready' => false, 'results' => []];
        }

        $results = get_transient('lp_carg_bulk_' . $jobId);

        return [
            'ready' => is_array($results),
            'results' => is_array($results) ? $results : [],
        ];
    }
}
