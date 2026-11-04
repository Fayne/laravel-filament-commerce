<p align="center"><img src="https://res.cloudinary.com/fayne/image/upload/v1787643269/fayne_logo.png" width="300"></p>

# Laravel Filament Commerce

简体中文 | [English](docs/README.en.md)

适用于 **PHP 8.4+、Laravel 13、Filament 5** 的电商领域包，支持租户隔离。

## 功能与边界

- 租户隔离的消费者、商品、SKU 和商品翻译模型；手机号和小程序身份按租户唯一，同一人可在不同商城拥有独立账户。
- 商品类型：实物、虚拟商品、服务。
- 按租户的 CMS 内容语言设置显示单语言或多语言商品编辑字段；商品默认语言名称必填。
- SKU 价格使用整数最小货币单位，默认币种 CNY（人民币以分计）；可通过 `app(TenantCurrency::class)->set()` 设置租户币种，已有 SKU 后禁止变更。
- CMS 租户权限目录中的 `Product`、`Order`、`RefundRequest`、`Stock`、`StockMovement` 权限控制后台资源；订单可取消、分批发货和按数量申请部分退款，库存变更和订单操作记录进入宿主审计系统。
- Filament 商品、订单、退款、库存及库存流水资源通过 `CommercePlugin` 注册到目标 Panel；订单详情显示虚拟商品/服务各单位的交付状态，不展示敏感结果。
- 支付确认时为虚拟商品与服务按购买数量建立独立交付任务，任务使用稳定 Handler 标识、幂等键及加密结果；宿主接入自己的发放逻辑。

## 1. 全新项目安装

先在宿主 Composer 中配置本包及相关 Fayne 依赖的私有软件源，再执行：

```bash
composer require fayne/laravel-filament-commerce
php artisan ec:core:install
```

安装命令发布缺失的 Commerce 配置，运行宿主迁移并初始化 CMS 权限目录。生产自动部署可使用 `php artisan ec:core:install --force --no-interaction`。

本地、测试和预发布环境安装时可选择生成两件示例实物商品及库存，以及已取消、待发货、部分发货和已发货的演示订单。订单为静态快照，不触发支付或物流，默认不生成。自动化使用 `--demo --no-interaction`，多租户模式还需传入 `--tenant=<租户ID>`。重复运行会跳过已有示例 SKU 和订单。


包依赖宿主的 `fayne/laravel-filament-cms-core`，并使用其 `TenantContext`、`BelongsToTenant` 与 `ContentLocale`。租户模式必须先由 CMS Core 设置可信上下文；无上下文的租户查询会被拒绝。

在目标 Filament Panel Provider 中注册：

```php
use Fayne\Commerce\CommercePlugin;

return $panel->plugin(CommercePlugin::make());
```

运行宿主迁移后即可创建电商领域表。包内 Policy 使用 CMS 租户权限目录控制访问；宿主仍需按业务需要分配权限。

## 当前限制

本包目前处于开发阶段。消费者短信/小程序登录 HTTP 接口、短信供应商实现、小程序租户凭据管理、Payment 包回调接线、物流轨迹及自动退款网关执行尚未完成。购物车结算适配、库存后台、过期预占命令和处理中断恢复命令已提供；交付结果读取 API 仅允许已登录消费者读取本人当前租户订单中成功任务的结果。购物车 SKU/金额与租户契约、Payment 商户隔离和独立收款仍需宿主联调验证。订单结算由宿主提供地址、运费及有效期；游客订单仅供后台客服按权限查询，不提供游客交付结果访问。商品交付配置不可存储外部服务密钥。撤销需要宿主配置供应商适配器。

## 租户消费者账户

消费者使用独立于 CMS 管理员的 `Customer` 模型和认证 guard。手机号验证码验证成功后，如果当前租户尚无该手机号账户，系统会自动创建；请求短信前必须先设置 CMS `TenantContext`：

```php
// config/auth.php
'guards' => ['customer' => ['driver' => 'session', 'provider' => 'commerce_customers']],
'providers' => ['commerce_customers' => ['driver' => 'eloquent', 'model' => \Fayne\Commerce\Customers\Models\Customer::class]],

// Tenant storefront controller
app(\Fayne\Commerce\Customers\Actions\SendPhoneVerificationCode::class)->execute('+8613800000000');
$customer = app(\Fayne\Commerce\Customers\Actions\AuthenticateCustomerByPhoneCode::class)
    ->execute('+8613800000000', $verificationCode);
\Illuminate\Support\Facades\Auth::guard('customer')->login($customer);
request()->session()->regenerate();
```

短信发送通过宿主绑定 `Fayne\Commerce\Customers\Contracts\SmsCodeSender` 完成。短信验证码 5 分钟有效、60 秒冷却、每手机号每日最多 10 次发送、每码最多 5 次校验；HTTP 入口还应按 IP 增加限流。代码只保存哈希。宿主需为 Customer 配置独立 session guard/provider，并确保租户解析中间件先于 guard 读取用户。验证码动作返回 Customer，不会自行登录 session。

11 位大陆手机号会规范化为 `+86` 格式；其他国际号码必须包含 `+` 国家码。小程序登录调用 `AuthenticateCustomerByMiniProgramCode`，宿主随后用同一 guard 登录返回的 Customer；宿主必须绑定 `MiniProgramCodeExchanger`，根据可信租户上下文使用该租户的小程序凭据向微信换取身份，不能信任客户端传入的 appid/openid。CMS Core 的 `WechatMiniAppSession` 服务用于管理员登录且使用全局 app 配置，不可直接用于租户消费者。`BindPhoneToCustomer` 与 `BindMiniProgramToCustomer` 可在已登录消费者账户上绑定另一种登录方式；不会自动合并已经分别创建的账户，也不提供历史账户合并流程。短信供应商、租户小程序凭据存储/管理、HTTP 登录路由与页面由宿主完成。游客下单继续使用 `customerId: null`；已登录下单需传入当前租户的有效消费者 ID。

## 结算报价

`QuoteProducts` 会从当前租户读取有效 SKU 的最新价格与商品名称，验证商品可售及实物库存，调用 Promotion 计算优惠，并返回行级折扣/退款依据。它是无副作用试算，不锁库存、不下单、不预占优惠券：

```php
$quote = app(\Fayne\Commerce\Pricing\Actions\QuoteProducts::class)->execute(
    requestedLines: [['variant_id' => 12, 'quantity' => 2]],
    shippingAmount: 0, // 由宿主的配送报价逻辑提供，不能直接信任客户端金额
    couponCode: null,
    customerId: null,
    locale: null, // 使用租户默认内容语言，也可传租户已启用的 locale
);
```

当前不计算税费，也不读取消费者分组；需要按消费者分组匹配的促销尚未接通。试算读取库存时不加锁，订单创建阶段必须重新核价并原子预占库存。

## 创建订单

订单必须使用 Commerce 生成的报价；游客下单要求提供姓名及有效邮箱或手机号。已登录消费者通过 `customerId` 关联当前租户消费者。`shippingAddress` 是宿主已校验的地址快照；有实物商品时必填。`reservationExpiresAt` 由宿主结算时限配置提供。

```php
$order = app(\Fayne\Commerce\Orders\Actions\PlaceOrder::class)->execute(
    quote: $quote,
    buyer: ['name' => 'Lin', 'phone' => '+8613800000000'],
    shippingAddress: ['recipient' => 'Lin', 'phone' => '+8613800000000', 'address' => '...'],
    idempotencyKey: $checkoutRequestId,
    reservationExpiresAt: now()->addMinutes(15),
);
```

创建订单会保存商品、价格、促销及交付快照，在同一数据库事务内预占实物库存/优惠券并写入 Outbox。支付通知、Outbox 派发/补偿和预占过期清理仍未接通；示例截止时间只是调用示例，实际时长由宿主决定。

## 库存基础动作

库存动作目前可由宿主服务调用：

```php
$level = app(\Fayne\Commerce\Inventory\Actions\AdjustStock::class)
    ->execute($variant, newOnHand: 20, reason: '盘点入库');

$reservation = app(\Fayne\Commerce\Inventory\Actions\ReserveStock::class)
    ->execute($variant, quantity: 2, sourceType: 'checkout', sourceId: 'request-123', expiresAt: now()->addMinutes(15));

app(\Fayne\Commerce\Inventory\Actions\CommitStockReservation::class)->execute($reservation->id);
// 未付款取消时：
app(\Fayne\Commerce\Inventory\Actions\ReleaseStockReservation::class)->execute($reservation->id);
```

预占以 `sourceType + sourceId + SKU` 为幂等范围。提交会同时减少现存量和预占量；释放只减少预占量。已过期预占不会被提交。库存管理页允许有 `tenant.adjustStock` 权限的管理员调整实物库存，所有变化写入只读库存流水并记录宿主合规操作。`commerce:stock:expire-reservations` 按租户清理到期预占；订单自有预占仍由订单过期流程处理。命令调度默认关闭，可在 `commerce.operations.schedule.enabled` 中启用。


## 分批发货

对已付款或零金额确认订单，后台可按实物订单行和数量创建多张发货单。每次创建会锁定订单及订单行，校验累计发货量不超过购买量，并扣除待审核、处理中、结果未知和已成功退款申请占用的数量；失败或拒绝的退款申请不再占用。发货记录保存承运商和物流单号，操作按幂等键去重。订单详情展示每张发货单的状态、承运商、物流单号、发货时间及关联订单行数量。虚拟商品与服务不显示物流发货操作。当前尚无物流轨迹同步。

## 虚拟商品与服务交付

宿主注册稳定标识到 Handler 类；数据库只保存稳定标识，不保存可执行类名。Handler 实现 `Fayne\Commerce\Fulfillment\Contracts\DeliveryHandler`，接收单个交付任务及幂等键，返回 `succeeded`、`failed` 或 `unknown`。宿主监听 `commerce.delivery.requested` Outbox 事件并调用 `ProcessDeliveryTask`。对接外部系统查单时可注册 `DeliveryStatusResolver` 并调用 `ReconcileDeliveryTask`。配置快照与交付结果均使用应用加密保存。`GET /api/commerce/customer/deliveries` 通过 `customer` guard 返回当前消费者本人订单的交付任务；仅 `succeeded` 的任务包含结果。`commerce:deliveries:recover` 会将超时处理中任务标记为 `unknown`，不会重试外部发放。撤销需要宿主配置供应商适配器。

## 按数量申请部分退款

已付款订单可按订单行数量创建退款申请。退款金额使用下单时记录的单位净价及优惠分摊快照计算；待审核、处理中、结果未知和已成功的申请会占用数量与金额额度，防止重复超退。后台可审核通过或拒绝申请。通过后状态进入 `processing` 并发布 `commerce.refund.approved` Outbox 事件；宿主监听器调用对应支付渠道，再使用 `RecordRefundResult` 回写 `succeeded`、`failed` 或 `unknown`。未知结果继续占用可退额度，明确失败或拒绝会释放额度。Outbox 至少投递一次，宿主处理器必须幂等。当前没有内置支付网关调用、运费退款或退货审批流程。

## Shopping Cart

将购物车 SKU 模型配置为 `Fayne\Commerce\Catalog\Models\ProductVariant`，并将 `Fayne\ShoppingCart\Contracts\CartCurrencyResolver` 绑定到 `Fayne\Commerce\Tenancy\Actions\TenantCurrency`。宿主应使用同一 CMS 租户上下文和 `customer` guard 解析购物车身份。订单由 Commerce 报价及创建，购物车消费在同一订单事务内执行。

同时将 `Fayne\ShoppingCart\Contracts\CartSkuQuery` 绑定到 `Fayne\Commerce\Cart\CommerceSkuQuery`，以可信租户 ID 约束购物车 SKU 查询。

使用独立消费者表时，请在首次运行购物车迁移前设置 `CART_ENFORCE_USER_FOREIGN_KEY=false`。消费者删除后的购物车清理由宿主负责。消费者 ID 仍使用整数列。

## 交付撤销

运行包迁移并向指定管理员授予 `tenant.revokeDelivery`，即可在订单详情申请撤销。宿主按交付 Handler 标识配置 `commerce.fulfillment.revokers` 和 `commerce.fulfillment.revocation_status_resolvers`。监听 `commerce.delivery.revocation_requested` 后，在事件所属租户上下文中用消息的 `delivery_task_id` 和 `attempt` 调用 `ProcessDeliveryRevocation::execute()`。超时处理由 `commerce:deliveries:recover` 转为未知，再调用 `ProcessDeliveryRevocation::reconcile()` 查询供应商；明确失败才可在后台重新申请。

## 包检查

在包根目录运行 `composer test` 和 `composer format`。在 GitHub Actions 中通过仓库变量 `PRIVATE_COMPOSER_URL` 配置提供私有依赖的 Composer 源，如需认证，通过 `COMPOSER_AUTH` secret 提供凭据。

## 许可证

本软件采用[专有许可证](LICENSE.md)，使用与分发须遵守其中的授权限制。
