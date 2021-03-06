<?php

declare(strict_types=1);

namespace Knyk\SyliusDotpayPlugin\Action;

use Knyk\SyliusDotpayPlugin\Api\DotpayApi;
use Knyk\SyliusDotpayPlugin\Factory\ActionDataFactory;
use Knyk\SyliusDotpayPlugin\Factory\HttpRequestFactory;
use Knyk\SyliusDotpayPlugin\Formatter\MoneyFormatter;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\InvalidArgumentException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Notify;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class NotifyAction implements ActionInterface, GatewayAwareInterface, ApiAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /**
     * @var DotpayApi
     */
    protected $api;
    private MoneyFormatter $moneyFormatter;
    private HttpRequestFactory $httpRequestFactory;
    private ActionDataFactory $actionDataFactory;

    public function __construct(
        MoneyFormatter $moneyFormatter,
        HttpRequestFactory $httpRequestFactory,
        ActionDataFactory $actionDataFactory
    ) {
        $this->apiClass = DotpayApi::class;
        $this->moneyFormatter = $moneyFormatter;
        $this->httpRequestFactory = $httpRequestFactory;
        $this->actionDataFactory = $actionDataFactory;
    }

    /**
     * @param Notify $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = $request->getModel();

        $getHttpRequest = $this->httpRequestFactory->createGet();
        $this->gateway->execute($getHttpRequest);

        if (isset($details[DotpayApi::STATUS_DETAILS_KEY])) {
            throw new NotFoundHttpException('Payment already confirmed.');
        }

        if (!isset($getHttpRequest->request['control'])) {
            throw new NotFoundHttpException('Control is missing.');
        }

        if ($details['control'] !== $getHttpRequest->request['control']) {
            throw new InvalidArgumentException('Control is invalid.');
        }

        $notifyActionData = $this->actionDataFactory->createNotifyActionData($getHttpRequest->request);

        $signature = $this->api->generateChecksum($notifyActionData->toArray());

        if ($signature !== $getHttpRequest->request['signature']) {
            throw new InvalidArgumentException('Signature is invalid.');
        }

        $details['dotpay_operation_number'] = $getHttpRequest->request['operation_number'];
        $details[DotpayApi::STATUS_DETAILS_KEY] = $getHttpRequest->request['operation_status'];
        $details['dotpay_operation_amount'] = $getHttpRequest->request['operation_amount'];
        $details['dotpay_operation_currency'] = $getHttpRequest->request['operation_currency'];
        $details['dotpay_operation_original_amount'] = $getHttpRequest->request['operation_original_amount'];
        $details['dotpay_operation_original_currency'] = $getHttpRequest->request['operation_original_currency'];
        $details['dotpay_operation_datetime'] = $getHttpRequest->request['operation_datetime'];

        throw new HttpResponse(DotpayApi::RESPONSE_NOTIFY_SUCCESS);
    }

    public function supports($request): bool
    {
        return $request instanceof Notify && $request->getModel() instanceof \ArrayAccess;
    }
}
