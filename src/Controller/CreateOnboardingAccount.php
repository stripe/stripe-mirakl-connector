<?php

namespace App\Controller;

use App\DTO\OnboardingAccountDTO;
use App\Exception\InvalidArgumentException;
use App\Factory\OnboardingAccountFactory;
use Nelmio\ApiDocBundle\Annotation\Security;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Swagger\Annotations as SWG;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class CreateOnboardingAccount extends AbstractController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var OnboardingAccountFactory
     */
    private $onboardingAccountFactory;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var ValidatorInterface
     */
    private $validator;

    public function __construct(
        OnboardingAccountFactory $onboardingAccountFactory,
        SerializerInterface $serializer,
        ValidatorInterface $validator
    ) {
        $this->onboardingAccountFactory = $onboardingAccountFactory;
        $this->serializer = $serializer;
        $this->validator = $validator;
    }

    /**
     * Generate the stripe redirect URL for seller onboarding.
     *
     * @SWG\Parameter(
     *     name="ids",
     *     in="body",
     *     type="string",
     *     description="Mirakl Shop Id",
     *     @SWG\Schema(
     *         type="object",
     *         example={"miraklShopId": 1}
     *      )
     * )
     *
     * @SWG\Response(
     *     response=200,
     *     description="",
     *     @SWG\Schema(type="object",
     *         @SWG\Property(property="redirect_url", type="string")
     *     )
     * )
     * @SWG\Response(
     *     response=400,
     *     description="Invalid Mirakl shop Id format",
     * )
     * @SWG\Response(
     *     response=409,
     *     description="Mirakl Shop ID is already linked to a Stripe Connect account",
     * )
     * @SWG\Response(
     *     response=401,
     *     description="Unauthorized access"
     * )
     * @SWG\Tag(name="OnboardingAccount")
     * @Security(name="Bearer")
     *
     * @Route("/api/onboarding", methods={"POST"}, name="generate_stripe_onboarding_link")
     */
    public function generateStripeOnboardingLink(Request $request): Response
    {
        $data = $request->getContent();
        $onboardingAccountDTO = $this->serializer->deserialize($data, OnboardingAccountDTO::class, JsonEncoder::FORMAT);

        $errors = $this->validator->validate($onboardingAccountDTO);
        if (count($errors) > 0) {
            $this->logger->error('Invalid Mirakl shop Id format');

            return new Response('Invalid Mirakl shop Id format', Response::HTTP_BAD_REQUEST);
        }

        $miraklId = $onboardingAccountDTO->getMiraklShopId();

        try {
            $stripeUrl = $this->onboardingAccountFactory->createFromMiraklShopId($miraklId);
        } catch (InvalidArgumentException $e) {
            $this->logger->error($e->getMessage(), [
                'miraklShopId' => $miraklId,
            ]);

            return new Response('Shop ID already mapped to a Stripe Account', Response::HTTP_CONFLICT);
        }

        $response = ['redirect_url' => $stripeUrl];
        $this->logger->info(sprintf('Generate Stripe Express URL for Mirakl Shop ID %s', $miraklId), $response);

        return new JsonResponse($response);
    }
}
