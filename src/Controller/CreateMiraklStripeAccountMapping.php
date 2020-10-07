<?php

namespace App\Controller;

use App\DTO\AccountMappingDTO;
use App\Factory\AccountMappingFactory;
use App\Repository\AccountMappingRepository;
use App\Repository\StripeAccountRepository;
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

class CreateMiraklStripeAccountMapping extends AbstractController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var StripeAccountRepository
     */
    private $stripeAccountRepository;

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var ValidatorInterface
     */
    private $validator;

    /**
     * @var AccountMappingFactory
     */
    private $accountMappingFactory;

    public function __construct(
        StripeAccountRepository $stripeAccountRepository,
        AccountMappingRepository $accountMappingRepository,
        SerializerInterface $serializer,
        ValidatorInterface $validator,
        AccountMappingFactory $accountMappingFactory
    ) {
        $this->stripeAccountRepository = $stripeAccountRepository;
        $this->accountMappingRepository = $accountMappingRepository;
        $this->serializer = $serializer;
        $this->validator = $validator;
        $this->accountMappingFactory = $accountMappingFactory;
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

        $mappingDTO = $this->serializer->deserialize($data, AccountMappingDTO::class, JsonEncoder::FORMAT);
        $stripeUserId = $mappingDTO->getStripeUserId();
        $stripeAccount = $this->stripeAccountRepository->setManualPayout($stripeUserId);

        $errors = $this->validator->validate($mappingDTO);
        if (count($errors) > 0) {
            return new Response('Invalid Mirakl shop Id format', Response::HTTP_BAD_REQUEST);
        }
        $mapping = $this->accountMappingFactory->createMappingFromDTO($mappingDTO);
        $mapping
            ->setPayinEnabled($stripeAccount->payouts_enabled)
            ->setPayoutEnabled($stripeAccount->charges_enabled)
            ->setDisabledReason($stripeAccount->requirements['disabled_reason']);

        $this->accountMappingRepository->persistAndFlush($mapping);

        return new Response('Mirakl - Stripe mapping created', Response::HTTP_CREATED);
    }
}
