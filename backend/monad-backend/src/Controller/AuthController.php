<?php

namespace App\Controller;

use App\Constants\ErrorCode;
use App\Entity\User;
use App\Exception\AuthException;
use App\Exception\SystemException;
use App\Exception\ValidationException;
use App\Join\BetaSignupLinker;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use OpenApi\Attributes as OA;

class AuthController extends AbstractController
{
    #[Route('/api/auth/register', name: 'api_register', methods: ['POST'])]
    #[OA\Post(
        path: '/api/auth/register',
        summary: 'Register a new user',
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com', description: 'User email address (must be unique)'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'securePassword123', description: 'User password'),
                new OA\Property(property: 'name', type: 'string', example: 'John Doe', description: 'User full name (optional)', nullable: true)
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'User successfully registered',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
                new OA\Property(property: 'name', type: 'string', example: 'John Doe', nullable: true),
                new OA\Property(property: 'token', type: 'string', example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...')
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request - validation errors',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'VALIDATION_101', description: 'Error code'),
                new OA\Property(property: 'message', type: 'string', example: 'Email address format is invalid', description: 'Human-readable error message')
            ]
        )
    )]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        JWTTokenManagerInterface $jwtManager,
        BetaSignupLinker $betaSignups,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        // Validate required fields
        if (!isset($data['email'])) {
            throw new ValidationException(ErrorCode::VALIDATION_EMAIL_REQUIRED);
        }

        if (!isset($data['password'])) {
            throw new ValidationException(ErrorCode::VALIDATION_PASSWORD_REQUIRED);
        }

        // Validate email format
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(ErrorCode::VALIDATION_EMAIL_INVALID);
        }

        $user = new User();
        $user->setEmail($data['email']);
        $user->setName($data['name'] ?? null);

        // Hash the password
        $hashedPassword = $passwordHasher->hashPassword(
            $user,
            $data['password']
        );
        $user->setPassword($hashedPassword);

        // Validate the user
        $errors = $validator->validate($user);
        if (count($errors) > 0) {
            $firstError = $errors->get(0);
            $fieldName = $firstError->getPropertyPath();
            $message = $firstError->getMessage();

            // Map validation errors to error codes
            $code = match ($fieldName) {
                'email' => str_contains($message, 'already')
                    ? ErrorCode::AUTH_EMAIL_ALREADY_EXISTS
                    : ErrorCode::VALIDATION_EMAIL_INVALID,
                'password' => ErrorCode::VALIDATION_PASSWORD_TOO_SHORT,
                'name' => ErrorCode::VALIDATION_NAME_TOO_LONG,
                default => ErrorCode::VALIDATION_FAILED,
            };

            throw new ValidationException($code);
        }

        try {
            $entityManager->persist($user);
            // IP-157: a beta signup with this email (case-insensitive) in `new` or `invited`
            // becomes `registered` and the account joins the beta cohort, in the same flush as
            // the account itself. The request and response shapes do not change.
            $betaSignups->link($user);
            $entityManager->flush();

            // Generate JWT token for immediate login
            $token = $jwtManager->create($user);

            return $this->json([
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'token' => $token
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            // Check if it's a duplicate email error
            if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'unique constraint')) {
                throw new AuthException(ErrorCode::AUTH_EMAIL_ALREADY_EXISTS, Response::HTTP_BAD_REQUEST);
            }

            throw new SystemException(ErrorCode::SYSTEM_INTERNAL_ERROR);
        }
    }

    #[Route('/api/auth/login', name: 'api_login', methods: ['POST'])]
    #[OA\Post(
        path: '/api/auth/login',
        summary: 'User login',
        description: 'Authenticates a user and returns a JWT token',
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com', description: 'User email address'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'securePassword123', description: 'User password')
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Successfully authenticated',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
                new OA\Property(property: 'name', type: 'string', example: 'John Doe', nullable: true),
                new OA\Property(property: 'token', type: 'string', example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...', description: 'JWT authentication token')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized - invalid credentials',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'AUTH_001', description: 'Error code'),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid email or password', description: 'Human-readable error message')
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request - validation errors',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'VALIDATION_100', description: 'Error code'),
                new OA\Property(property: 'message', type: 'string', example: 'Email address is required', description: 'Human-readable error message')
            ]
        )
    )]
    public function loginCheck(): JsonResponse
    {
        // This method can be blank - it will be intercepted by the json_login firewall
        return $this->json([
            'message' => 'Login endpoint'
        ]);
    }

    #[Route('/api/auth/account', name: 'api_delete_account', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/auth/account',
        summary: 'Delete current user account (soft delete)',
        description: 'Soft-deletes the authenticated user account by anonymizing personal data and marking it as deleted',
        security: [['Bearer' => []]],
        tags: ['User']
    )]
    #[OA\Response(
        response: 200,
        description: 'Account successfully deleted',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Account deleted successfully')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized - missing or invalid token'
    )]
    public function deleteAccount(EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AuthException(ErrorCode::AUTH_UNAUTHORIZED);
        }

        $user->softDelete();
        $entityManager->flush();

        return $this->json([
            'message' => 'Account deleted successfully'
        ]);
    }

    #[Route('/api/auth/me', name: 'api_me', methods: ['GET'])]
    #[OA\Get(
        path: '/api/auth/me',
        summary: 'Get current user information',
        description: 'Returns the authenticated user profile information',
        security: [['Bearer' => []]],
        tags: ['User']
    )]
    #[OA\Response(
        response: 200,
        description: 'User information retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com', description: 'User email address'),
                new OA\Property(property: 'name', type: 'string', example: 'John Doe', nullable: true, description: 'User full name'),
                new OA\Property(property: 'is_operator', type: 'boolean', example: false, description: 'May this account reach the operator surfaces (lab console, operator takes)? True for ROLE_SUPERADMIN.')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized - missing or invalid token',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'AUTH_006', description: 'Error code'),
                new OA\Property(property: 'message', type: 'string', example: 'Authentication required', description: 'Human-readable error message')
            ]
        )
    )]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AuthException(ErrorCode::AUTH_UNAUTHORIZED);
        }

        return $this->json([
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            // Whether this account may see the operator half of the app: the lab console, the
            // walk console, the instrument panels and the operator takes on the board.
            //
            // A capability, not the role string. The app needs one boolean to decide what to
            // draw, and publishing `roles` instead would make every future role a wire change
            // in two repositories. It reuses ROLE_SUPERADMIN, which is already what
            // `QuestController::list()` filters operator quests on and what gates /admin and
            // /mcp — so "may walk an operator take" and "may administer the lab" stay the same
            // grant, and an operator take still cannot be delegated to a student helper.
            //
            // This is NOT the authorization. Every operator surface that touches the server is
            // gated server-side on its own; this field only stops the app drawing doors that
            // would be refused.
            'is_operator' => $this->isGranted('ROLE_SUPERADMIN'),
        ]);
    }
}
