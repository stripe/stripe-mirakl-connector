<?php

namespace App\Controller;

use App\DTO\AccountMappingDTO;
use App\Entity\AccountMapping;
use App\Repository\AccountMappingRepository;
use App\Service\StripeClient;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AccountMappingByOperator extends AbstractController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private AccountMappingRepository $accountMappingRepository;
    private StripeClient $stripeClient;
    private SerializerInterface $serializer;
    private ValidatorInterface $validator;

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

    #[Route('/api/mappings', methods: ['POST'], name: 'create_mapping_manually')]
    #[OA\Post(
        summary: 'Create Stripe-Mirakl mapping',
        description: 'Manually creates the Stripe-Mirakl mapping. Should only be called manually.',
        requestBody: new OA\RequestBody(
            description: 'Mirakl and Stripe Ids to map',
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                example: ['miraklShopId' => 1, 'stripeUserId' => '12345']
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Mirakl - Stripe mapping created'),
            new OA\Response(response: 400, description: 'Invalid Mirakl shop Id format or cannot find the Stripe account corresponding to this stripe Id'),
            new OA\Response(response: 401, description: 'Unauthorized access'),
            new OA\Response(response: 409, description: 'The provided Mirakl Shop ID or Stripe User Id is already mapped'),
        ],
        tags: ['AccountMapping'],
        security: [['Bearer' => []]]
    )]
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
