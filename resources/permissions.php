<?php

return [
    'resources' => ['Product', 'Order', 'RefundRequest', 'Stock', 'StockMovement'],
    'permissions' => [
        'all' => ['tenant.cancelOrder', 'tenant.shipOrder', 'tenant.requestRefund', 'tenant.reviewRefund', 'tenant.adjustStock', 'tenant.revokeDelivery'],
        'editor' => ['tenant.cancelOrder', 'tenant.shipOrder', 'tenant.requestRefund', 'tenant.reviewRefund', 'tenant.adjustStock', 'tenant.revokeDelivery'],
        'viewer' => [],
    ],
];
