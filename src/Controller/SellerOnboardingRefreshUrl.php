<?php

namespace App\Controller;

use App\Repository\AccountMappingRepository;
use App\Service\SellerOnboardingService;
use App\Service\StripeClient;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SellerOnboardingRefreshUrl extends AbstractController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    /**
     * @var SellerOnboardingService
     */
    private $sellerOnboardingService;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    public function getSerializer(): mixed
    {
        return $this->serializer;
    }

    /**
     * @var ValidatorInterface
     */
    private $validator;

    public function getValidator(): mixed
    {
        return $this->validator;
    }

    public function __construct(
        AccountMappingRepository $accountMappingRepository,
        SellerOnboardingService $sellerOnboardingService,
        StripeClient $stripeClient,
        SerializerInterface $serializer,
        ValidatorInterface $validator
    ) {
        $this->accountMappingRepository = $accountMappingRepository;
        $this->sellerOnboardingService = $sellerOnboardingService;
        $this->stripeClient = $stripeClient;
        $this->serializer = $serializer;
        $this->validator = $validator;
    }

    /**
     * Should only be called by Stripe if the AccountLink expired.
     *
     * @OA\Response(
     *     response=302,
     *     description="Redirect to Stripe",
     * )
     * @OA\Response(
     *     response=400,
     *     description="Bad request",
     * )
     *
     * @OA\Tag(name="Internal (Stripe Only)")
     *
     * @Route("/api/public/onboarding/refresh", methods={"GET"}, name="onboarding_refresh")
     */
    public function onboardingRefresh(Request $request): Response
    {
        // Retrieve onboarding token
        $token = (string) $request->query->get('token');
        if (!$token) {
            return new Response('Incorrect token', Response::HTTP_BAD_REQUEST);
        }

        // Retrieve AccountMapping
        $accountMapping = $this->accountMappingRepository->findOneByOnboardingToken($token);
        if (!$accountMapping) {
            return new Response('Incorrect token', Response::HTTP_BAD_REQUEST);
        }

        // Retrieve Stripe Account
        $stripeAccount = $this->stripeClient->retrieveAccount($accountMapping->getStripeAccountId());

        // Add AccountLink or LoginLink depending on submission status
        if (!$stripeAccount['details_submitted']) {
            $url = $this->sellerOnboardingService->addOnboardingLinkToShop($accountMapping->getMiraklShopId(), $accountMapping);
        } else {
            $url = $this->sellerOnboardingService->addLoginLinkToShop($accountMapping->getMiraklShopId(), $accountMapping);
        }

        return new RedirectResponse($url);
    }
}
