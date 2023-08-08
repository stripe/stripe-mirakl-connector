<?php

namespace App\Controller;

use App\DTO\AccountMappingDTO;
use App\Entity\AccountMapping;
use App\Repository\AccountMappingRepository;
use App\Service\StripeClient;
use Nelmio\ApiDocBundle\Annotation\Security;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
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
        AccountMappingRepository $accountMappingRepository,
        StripeClient $stripeClient,
        SerializerInterface $serializer,
        ValidatorInterface $validator
    ) {
        $this->accountMappingRepository = $accountMappingRepository;
        $this->stripeClient = $stripeClient;
        $this->serializer = $serializer;
        $this->validator = $validator;
    }

    /**
     * Manually creates the Stripe-Mirakl mapping.
     * Should only be called manually.
     *
     *   @OA\Parameter(
     *     name="ids",
     *     in="body",
     *     description="Mirakl and Stripe Ids to map",
     *
     *     @OA\Schema(
     *         type="object",
     *         example={"miraklShopId": 1, "stripeUserId": "12345"}
     *      )
     * )
     *
     * @OA\Response(
     *     response=201,
     *     description="Mirakl - Stripe mapping created",
     * )
     * @OA\Response(
     *     response=400,
     *     description="
     * Invalid Mirakl shop Id format
     * Cannot find the Stripe account corresponding to this stripe Id",
     * )
     * @OA\Response(
     *     response=401,
     *     description="Unauthorized access"
     * )
     * @OA\Response(
     *     response=409,
     *     description="The provided Mirakl Shop ID or Stripe User Id is already mapped",
     * )
     *
     * @OA\Tag(name="AccountMapping")
     *
     * @Security(name="Bearer")
     *
     * @Route("/api/mappings", methods={"POST"}, name="create_mapping_manually")
     */
    public function createMapping(Request $request): Response
    {
        $data = $request->getContent();
        $dto = $this->serializer->deserialize($data, AccountMappingDTO::class, JsonEncoder::FORMAT);
        assert(is_object($dto) && is_a($dto, AccountMappingDTO::class));

        if (count($this->validator->validate($dto)) > 0) {
            return new Response('Invalid Mirakl Shop ID', Response::HTTP_BAD_REQUEST);
        }

        $miraklShopId = $dto->getMiraklShopId();
        $stripeUserId = $dto->getStripeUserId();
        $stripeAccount = $this->stripeClient->retrieveAccount($stripeUserId);

        $mapping = new AccountMapping();
        $mapping->setMiraklShopId($miraklShopId);
        $mapping->setStripeAccountId($stripeUserId);
        $mapping->setPayinEnabled((bool) $stripeAccount->payouts_enabled);
        $mapping->setPayoutEnabled((bool) $stripeAccount->charges_enabled);

        if (isset($stripeAccount->requirements['disabled_reason'])) {
            /** @var string $disabledReasonData */
            $disabledReasonData = $stripeAccount->requirements['disabled_reason'];
            $mapping->setDisabledReason($disabledReasonData);
        }

        $this->accountMappingRepository->persistAndFlush($mapping);

        return new Response('Mirakl - Stripe mapping created', Response::HTTP_CREATED);
    }
}
