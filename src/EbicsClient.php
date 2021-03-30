<?php

namespace AndrewSvirin\Ebics;

use AndrewSvirin\Ebics\Contracts\EbicsClientInterface;
use AndrewSvirin\Ebics\Contracts\HttpClientInterface;
use AndrewSvirin\Ebics\Contracts\OrderDataInterface;
use AndrewSvirin\Ebics\Contracts\X509GeneratorInterface;
use AndrewSvirin\Ebics\Exceptions\EbicsException;
use AndrewSvirin\Ebics\Factories\EbicsExceptionFactory;
use AndrewSvirin\Ebics\Factories\RequestFactory;
use AndrewSvirin\Ebics\Factories\SignatureFactory;
use AndrewSvirin\Ebics\Handlers\OrderDataHandler;
use AndrewSvirin\Ebics\Handlers\ResponseHandler;
use AndrewSvirin\Ebics\Models\Bank;
use AndrewSvirin\Ebics\Models\Http\Request;
use AndrewSvirin\Ebics\Models\Http\Response;
use AndrewSvirin\Ebics\Models\KeyRing;
use AndrewSvirin\Ebics\Models\User;
use AndrewSvirin\Ebics\Services\CryptService;
use AndrewSvirin\Ebics\Services\HttpClient;
use DateTime;
use DateTimeInterface;

/**
 * EBICS client representation. 1
 *
 * @license http://www.opensource.org/licenses/mit-license.html  MIT License
 * @author Andrew Svirin
 */
final class EbicsClient implements EbicsClientInterface
{
    /**
     * An EbicsBank instance.
     *
     * @var Bank
     */
    private $bank;

    /**
     * An EbicsUser instance.
     *
     * @var User
     */
    private $user;

    /**
     * @var KeyRing
     */
    private $keyRing;

    /**
     * @var OrderDataHandler
     */
    private $orderDataHandler;

    /**
     * @var ResponseHandler
     */
    private $responseHandler;

    /**
     * @var RequestFactory
     */
    private $requestFactory;

    /**
     * @var CryptService
     */
    private $cryptService;

    /**
     * @var SignatureFactory
     */
    private $signatureFactory;

    /**
     * @var X509GeneratorInterface|null
     */
    private $x509Generator;

    /**
     * @var HttpClientInterface
     */
    private $httpClient;

    /**
     * Constructor.
     *
     * @param Bank $bank
     * @param User $user
     * @param KeyRing $keyRing
     */
    public function __construct(Bank $bank, User $user, KeyRing $keyRing)
    {
        $this->bank = $bank;
        $this->user = $user;
        $this->keyRing = $keyRing;
        $this->requestFactory = new RequestFactory($bank, $user, $keyRing);
        $this->orderDataHandler = new OrderDataHandler($bank, $user, $keyRing);
        $this->responseHandler = new ResponseHandler();
        $this->cryptService = new CryptService();
        $this->signatureFactory = new SignatureFactory();
        // Set default http client.
        $this->httpClient = new HttpClient();
    }

    /**
     * @inheritDoc
     */
    public function HEV(): Response
    {
        $request = $this->requestFactory->createHEV();
        $response = $this->httpClient->post($this->bank->getUrl(), $request);

        $this->checkH000ReturnCode($request, $response);

        return $response;
    }

    /**
     * @inheritDoc
     *
     * @throws EbicsException
     */
    public function INI(DateTimeInterface $dateTime = null): Response
    {
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }
        $signatureA = $this->signatureFactory->createSignatureAFromKeys(
            $this->cryptService->generateKeys($this->keyRing->getPassword()),
            $this->keyRing->getPassword(),
            $this->bank->isCertified() ? $this->x509Generator : null
        );
        $request = $this->requestFactory->createINI($signatureA, $dateTime);
        $response = $this->httpClient->post($this->bank->getUrl(), $request);

        $this->checkH004ReturnCode($request, $response);
        $this->keyRing->setUserSignatureA($signatureA);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws EbicsException
     */
    public function HIA(DateTimeInterface $dateTime = null): Response
    {
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }
        $signatureE = $this->signatureFactory->createSignatureEFromKeys(
            $this->cryptService->generateKeys($this->keyRing->getPassword()),
            $this->keyRing->getPassword(),
            $this->bank->isCertified() ? $this->x509Generator : null
        );
        $signatureX = $this->signatureFactory->createSignatureXFromKeys(
            $this->cryptService->generateKeys($this->keyRing->getPassword()),
            $this->keyRing->getPassword(),
            $this->bank->isCertified() ? $this->x509Generator : null
        );
        $request = $this->requestFactory->createHIA($signatureE, $signatureX, $dateTime);
        $response = $this->httpClient->post($this->bank->getUrl(), $request);

        $this->checkH004ReturnCode($request, $response);
        $this->keyRing->setUserSignatureE($signatureE);
        $this->keyRing->setUserSignatureX($signatureX);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws Exceptions\EbicsException
     */
    public function HPB(DateTimeInterface $dateTime = null): Response
    {
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }
        $request = $this->requestFactory->createHPB($dateTime);
        $response = $this->httpClient->post($this->bank->getUrl(), $request);

        $this->checkH004ReturnCode($request, $response);
        $transaction = $this->responseHandler->retrieveTransaction($response);
        $response->addTransaction($transaction);
        $orderData = $this->responseHandler->retrieveOrderData($response, $transaction->getKey(), $this->keyRing);
        $signatureX = $this->orderDataHandler->retrieveAuthenticationSignature($orderData);
        $signatureE = $this->orderDataHandler->retrieveEncryptionSignature($orderData);
        $this->keyRing->setBankSignatureX($signatureX);
        $this->keyRing->setBankSignatureE($signatureE);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws Exceptions\EbicsException
     */
    public function HPD(DateTimeInterface $dateTime = null): Response
    {
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }
        $request = $this->requestFactory->createHPD($dateTime);
        $response = $this->retrieveOrderData($request);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws Exceptions\EbicsException
     */
    public function HKD(DateTimeInterface $dateTime = null): Response
    {
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }
        $request = $this->requestFactory->createHKD($dateTime);
        $response = $this->retrieveOrderData($request);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws Exceptions\EbicsException
     */
    public function HTD(DateTimeInterface $dateTime = null): Response
    {
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }

        $request = $this->requestFactory->createHTD($dateTime);
        $response = $this->retrieveOrderData($request);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws Exceptions\EbicsException
     */
    public function PTK(DateTimeInterface $dateTime = null): Response
    {
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }
        $request = $this->requestFactory->createPTK($dateTime);
        $response = $this->retrievePlainOrderData($request);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws Exceptions\EbicsException
     */
    public function HAA(DateTimeInterface $dateTime = null): Response
    {
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }
        $request = $this->requestFactory->createHAA($dateTime);
        $response = $this->retrieveOrderData($request);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws Exceptions\EbicsException
     */
    public function VMK(
        DateTimeInterface $dateTime = null,
        DateTimeInterface $startDateTime = null,
        DateTimeInterface $endDateTime = null
    ): Response {
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }
        $request = $this->requestFactory->createVMK($dateTime, $startDateTime, $endDateTime);
        $response = $this->retrievePlainOrderData($request);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws Exceptions\EbicsException
     */
    public function STA(
        DateTimeInterface $dateTime = null,
        DateTimeInterface $startDateTime = null,
        DateTimeInterface $endDateTime = null
    ): Response {
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }
        $request = $this->requestFactory->createSTA($dateTime, $startDateTime, $endDateTime);
        $response = $this->retrievePlainOrderData($request);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws Exceptions\EbicsException
     */
    // @codingStandardsIgnoreStart
    public function C53(
        DateTimeInterface $dateTime = null,
        DateTimeInterface $startDateTime = null,
        DateTimeInterface $endDateTime = null
    ): Response {
        // @codingStandardsIgnoreEnd
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }
        $request = $this->requestFactory->createC53($dateTime, $startDateTime, $endDateTime);
        $response = $this->retrieveOrderDataItems($request);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws Exceptions\EbicsException
     */
    // @codingStandardsIgnoreStart
    public function Z53(
        DateTimeInterface $dateTime = null,
        DateTimeInterface $startDateTime = null,
        DateTimeInterface $endDateTime = null
    ): Response {
        // @codingStandardsIgnoreEnd
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }
        $request = $this->requestFactory->createZ53($dateTime, $startDateTime, $endDateTime);
        $response = $this->retrievePlainOrderData($request);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws Exceptions\EbicsException
     */
    public function FDL(
        $fileInfo,
        $format = 'plain',
        $countryCode = 'FR',
        DateTimeInterface $dateTime = null,
        DateTimeInterface $startDateTime = null,
        DateTimeInterface $endDateTime = null
    ): Response {
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }

        $request = $this->requestFactory->createFDL($dateTime, $fileInfo, $countryCode, $startDateTime, $endDateTime);
        $response = $this->httpClient->post($this->bank->getUrl(), $request);

        $this->checkH004ReturnCode($request, $response);

        $transaction = $this->responseHandler->retrieveTransaction($response);
        $response->addTransaction($transaction);

        switch ($format) {
            case 'plain':
                $response = $this->retrievePlainOrderData($request);
                break;
            case 'xml':
            default:
                $response = $this->retrieveOrderData($request);
                break;
        }

        return $response;
    }

    /**
     * @inheritDoc
     * @throws Exceptions\EbicsResponseException
     */
    public function CCT(
        OrderDataInterface $orderData,
        DateTimeInterface $dateTime = null,
        int $numSegments = 1
    ): Response {
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }

        $transactionKey = $this->cryptService->generateTransactionKey();

        $request = $this->requestFactory->createCCT($dateTime, $numSegments, $transactionKey, $orderData);
        $response = $this->retrieveTransaction($request, $orderData, $numSegments, $transactionKey);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws Exceptions\EbicsResponseException
     */
    public function CDD(
        OrderDataInterface $orderData,
        DateTimeInterface $dateTime = null,
        int $numSegments = 1
    ): Response {
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }

        $transactionKey = $this->cryptService->generateTransactionKey();

        $request = $this->requestFactory->createCDD($dateTime, $numSegments, $transactionKey, $orderData);
        $response = $this->retrieveTransaction($request, $orderData, $numSegments, $transactionKey);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws EbicsException
     */
    public function transferReceipt(Response $response, bool $acknowledged = true): Response
    {
        $lastTransaction = $response->getLastTransaction();
        if (null === $lastTransaction) {
            throw new EbicsException('There is no transactions to mark as received.');
        }

        $request = $this->requestFactory->createTransferReceipt($lastTransaction->getId(), $acknowledged);
        $response = $this->httpClient->post($this->bank->getUrl(), $request);

        $this->checkH004ReturnCode($request, $response);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws EbicsException
     */
    public function transferTransfer(Response $response): Response
    {
        $lastTransaction = $response->getLastTransaction();
        if (null === $lastTransaction) {
            throw new EbicsException('There is no transactions to mark as transferred.');
        }

        $lastTransaction->setSegmentNumber(1);
        $request = $this->requestFactory->createTransferTransfer(
            $lastTransaction->getId(),
            $lastTransaction->getKey(),
            $lastTransaction->getOrderData(),
            $lastTransaction->getSegmentNumber()
        );
        $response = $this->httpClient->post($this->bank->getUrl(), $request);

        $this->checkH004ReturnCode($request, $response);

        return $response;
    }

    /**
     * @param Request $request
     * @param Response $response
     *
     * @throws Exceptions\EbicsResponseException
     */
    private function checkH004ReturnCode(Request $request, Response $response): void
    {
        $errorCode = $this->responseHandler->retrieveH004BodyOrHeaderReturnCode($response);

        if ('000000' === $errorCode) {
            return;
        }

        // For Transaction Done.
        if ('011000' === $errorCode) {
            return;
        }

        $reportText = $this->responseHandler->retrieveH004ReportText($response);
        EbicsExceptionFactory::buildExceptionFromCode($errorCode, $reportText, $request, $response);
    }

    /**
     * @param Request $request
     * @param Response $response
     *
     * @throws Exceptions\EbicsResponseException
     */
    private function checkH000ReturnCode(Request $request, Response $response): void
    {
        $errorCode = $this->responseHandler->retrieveH000ReturnCode($response);

        if ('000000' === $errorCode) {
            return;
        }

        $reportText = $this->responseHandler->retrieveH000ReportText($response);
        EbicsExceptionFactory::buildExceptionFromCode($errorCode, $reportText, $request, $response);
    }

    /**
     * @param Request $request
     *
     * @return Response
     * @throws EbicsException
     * @throws Exceptions\EbicsResponseException
     */
    private function retrieveOrderData(Request $request)
    {
        $response = $this->httpClient->post($this->bank->getUrl(), $request);

        $this->checkH004ReturnCode($request, $response);

        $transaction = $this->responseHandler->retrieveTransaction($response);
        $response->addTransaction($transaction);

        $orderData = $this->responseHandler->retrieveOrderData($response, $transaction->getKey(), $this->keyRing);
        $transaction->setOrderData($orderData);

        return $response;
    }

    /**
     * @param Request $request
     *
     * @return Response
     * @throws EbicsException
     * @throws Exceptions\EbicsResponseException
     */
    private function retrievePlainOrderData(Request $request)
    {
        $response = $this->httpClient->post($this->bank->getUrl(), $request);

        $this->checkH004ReturnCode($request, $response);

        $transaction = $this->responseHandler->retrieveTransaction($response);
        $response->addTransaction($transaction);

        $plainOrderData = $this->responseHandler->retrievePlainOrderData(
            $response,
            $transaction->getKey(),
            $this->keyRing
        );
        $transaction->setPlainOrderData($plainOrderData);

        return $response;
    }

    /**
     * @param Request $request
     *
     * @return Response
     * @throws EbicsException
     * @throws Exceptions\EbicsResponseException
     */
    private function retrieveOrderDataItems(Request $request)
    {
        $response = $this->httpClient->post($this->bank->getUrl(), $request);

        $this->checkH004ReturnCode($request, $response);

        $transaction = $this->responseHandler->retrieveTransaction($response);
        $response->addTransaction($transaction);

        $plainOrderData = $this->responseHandler->retrieveOrderDataItems(
            $response,
            $transaction->getKey(),
            $this->keyRing
        );
        $transaction->setOrderDataItems($plainOrderData);

        return $response;
    }

    /**
     * @param Request $request
     * @param OrderDataInterface $orderData
     * @param int $numSegments
     * @param string $transactionKey
     *
     * @return Response
     * @throws Exceptions\EbicsResponseException
     */
    private function retrieveTransaction(
        Request $request,
        OrderDataInterface $orderData,
        int $numSegments,
        string $transactionKey
    ) {
        $response = $this->httpClient->post($this->bank->getUrl(), $request);

        $this->checkH004ReturnCode($request, $response);

        $transaction = $this->responseHandler->retrieveTransaction($response);
        $transaction->setOrderData($orderData);
        $transaction->setNumSegments($numSegments);
        $transaction->setKey($transactionKey);
        $response->addTransaction($transaction);

        return $response;
    }

    /**
     * @return KeyRing
     */
    public function getKeyRing(): KeyRing
    {
        return $this->keyRing;
    }

    /**
     * @return Bank
     */
    public function getBank(): Bank
    {
        return $this->bank;
    }

    /**
     * @return User
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @inheritDoc
     */
    public function setX509Generator(X509GeneratorInterface $x509Generator = null): void
    {
        $this->x509Generator = $x509Generator;
    }

    /**
     * @inheritDoc
     */
    public function setHttpClient(HttpClientInterface $httpClient): void
    {
        $this->httpClient = $httpClient;
    }
}
