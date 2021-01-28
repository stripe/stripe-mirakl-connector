<?php

namespace App\Controller;

use App\DTO\AccountMappingDTO;
use App\Factory\AccountMappingFactory;
use App\Repository\AccountMappingRepository;
use App\Service\StripeClient;
use Nelmio\ApiDocBundle\Annotation\Security;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Swagger\Annotations as SWG;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AccountMappingByOperator extends AbstractController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var AccountMappingFactory
     */
    private $accountMappingFactory;

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var ValidatorInterface
     */
    private $validator;

    public function __construct(
        AccountMappingFactory $accountMappingFactory,
        AccountMappingRepository $accountMappingRepository,
        StripeClient $stripeClient,
        SerializerInterface $serializer,
        ValidatorInterface $validator
    ) {
        $this->accountMappingFactory = $accountMappingFactory;
        $this->accountMappingRepository = $accountMappingRepository;
        $this->stripeClient = $stripeClient;
        $this->serializer = $serializer;
        $this->validator = $validator;
    }

    /**
     * Manually creates the Stripe-Mirakl mapping.
     * Should only be called manually.
     *
     *   @SWG\Parameter(
     *     name="ids",
     *     in="body",
     *     type="string",
     *     description="Mirakl and Stripe Ids to map",
     *     @SWG\Schema(
     *         type="object",
     *         example={"miraklShopId": 1, "stripeUserId": "12345"}
     *      )
     * )
     *
     * @SWG\Response(
     *     response=201,
     *     description="Mirakl - Stripe mapping created",
     * )
     * @SWG\Response(
     *     response=400,
     *     description="
     * Invalid Mirakl shop Id format
     * Cannot find the Stripe account corresponding to this stripe Id",
     * )
     * @SWG\Response(
     *     response=401,
     *     description="Unauthorized access"
     * )
     * @SWG\Response(
     *     response=409,
     *     description="The provided Mirakl Shop ID or Stripe User Id is already mapped",
     * )
     * @SWG\Tag(name="AccountMapping")
     * @Security(name="Bearer")
     * @Route("/api/mappings", methods={"POST"}, name="create_mapping_manually")
     */
    public function createMapping(Request $request): Response
    {
        $data = $request->getContent();
        $dto = $this->serializer->deserialize($data, AccountMappingDTO::class, JsonEncoder::FORMAT);
        assert(is_object($dto) && is_a($dto, AccountMappingDTO::class));

        $stripeAccount = $this->stripeClient->setPayoutToManual($dto->getStripeUserId());

        if (count($this->validator->validate($dto)) > 0) {
            return new Response('Invalid Mirakl shop Id format', Response::HTTP_BAD_REQUEST);
        }
        $mapping = $this->accountMappingFactory->createMappingFromDTO($dto);
        $mapping->setPayinEnabled($stripeAccount->payouts_enabled);
        $mapping->setPayoutEnabled($stripeAccount->charges_enabled);
        $mapping->setDisabledReason($stripeAccount->requirements['disabled_reason']);

        $this->accountMappingRepository->persistAndFlush($mapping);

        return new Response('Mirakl - Stripe mapping created', Response::HTTP_CREATED);
    }
}
