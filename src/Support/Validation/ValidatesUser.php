<?php

namespace Vampires\Sentinels\Support\Validation;

use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Core\ValidationResult;

/**
 * Validation trait for user-related payloads.
 *
 * Provides validation patterns for user registration, profile updates,
 * authentication, and permission checks.
 */
trait ValidatesUser
{
    /**
     * Validate user payload with common user business rules.
     *
     * @param Context $context The context containing user data
     * @param array<string, mixed> $additionalRules Additional validation rules
     */
    protected function validateUser(Context $context, array $additionalRules = []): ValidationResult
    {
        $payload = $context->payload;
        $userData = $this->extractUserData($payload);

        if ($userData === null) {
            return ValidationResult::invalid(['user' => ['Invalid user data structure']]);
        }

        $rules = array_merge([
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'min:2'],
        ], $additionalRules);

        return $this->validateWithRules($userData, $rules);
    }

    /**
     * Validate user registration data.
     *
     * @param Context $context The registration context
     * @param array<string, mixed> $passwordRules Custom password validation rules
     */
    protected function validateUserRegistration(
        Context $context,
        array $passwordRules = []
    ): ValidationResult {
        $userData = $this->extractUserData($context->payload);
        
        if ($userData === null) {
            return ValidationResult::invalid(['user' => ['Invalid registration data']]);
        }

        $defaultPasswordRules = [
            'required',
            'string',
            'min:8',
            'confirmed', // Laravel's password confirmation rule
        ];

        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => array_merge($defaultPasswordRules, $passwordRules),
            'terms_accepted' => ['required', 'accepted'],
        ];

        $validation = $this->validateWithRules($userData, $rules);

        if (!$validation->valid) {
            return $validation;
        }

        // Additional security validations
        return $this->validateUserSecurity($userData);
    }

    /**
     * Validate user profile update data.
     *
     * @param Context $context The update context
     * @param bool $requireCurrentPassword Whether current password is required
     */
    protected function validateUserProfileUpdate(
        Context $context,
        bool $requireCurrentPassword = true
    ): ValidationResult {
        $userData = $this->extractUserData($context->payload);
        
        if ($userData === null) {
            return ValidationResult::invalid(['user' => ['Invalid profile data']]);
        }

        $rules = [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];

        // Require current password for sensitive updates
        if ($requireCurrentPassword && (isset($userData['email']) || isset($userData['password']))) {
            $rules['current_password'] = ['required', 'string'];
        }

        // Validate new password if provided
        if (isset($userData['password'])) {
            $rules['password'] = ['required', 'string', 'min:8', 'confirmed'];
        }

        return $this->validateWithRules($userData, $rules);
    }

    /**
     * Validate user permissions and access rights.
     *
     * @param Context $context The operation context
     * @param array<string> $requiredPermissions Required permissions
     * @param array<string> $requiredRoles Required roles
     */
    protected function validateUserPermissions(
        Context $context,
        array $requiredPermissions = [],
        array $requiredRoles = []
    ): ValidationResult {
        $errors = [];

        // Check if user is authenticated
        if (!$context->hasTag('authenticated')) {
            return ValidationResult::invalid(['auth' => ['User must be authenticated']]);
        }

        // Get user permissions from metadata
        $userPermissions = $context->getMetadata('user_permissions', []);
        $userRoles = $context->getMetadata('user_roles', []);

        // Check required permissions
        foreach ($requiredPermissions as $permission) {
            if (!in_array($permission, $userPermissions, true)) {
                $errors['permissions'] = $errors['permissions'] ?? [];
                $errors['permissions'][] = "Missing required permission: {$permission}";
            }
        }

        // Check required roles
        foreach ($requiredRoles as $role) {
            if (!in_array($role, $userRoles, true)) {
                $errors['roles'] = $errors['roles'] ?? [];
                $errors['roles'][] = "Missing required role: {$role}";
            }
        }

        // Check if account is active
        $accountStatus = $context->getMetadata('account_status', 'active');
        if ($accountStatus !== 'active') {
            $errors['account'] = ["Account is {$accountStatus}"];
        }

        return empty($errors) 
            ? ValidationResult::valid($context->payload)
            : ValidationResult::invalid($errors);
    }

    /**
     * Validate user authentication data.
     *
     * @param Context $context The authentication context
     * @param array<string> $allowedMethods Allowed authentication methods
     */
    protected function validateUserAuthentication(
        Context $context,
        array $allowedMethods = ['password', 'oauth', 'api_token']
    ): ValidationResult {
        $userData = $this->extractUserData($context->payload);
        
        if ($userData === null) {
            return ValidationResult::invalid(['auth' => ['Invalid authentication data']]);
        }

        $errors = [];

        // Validate authentication method
        $authMethod = $userData['auth_method'] ?? 'password';
        if (!in_array($authMethod, $allowedMethods, true)) {
            $errors['auth_method'] = [
                "Authentication method '{$authMethod}' not allowed. " .
                'Allowed methods: ' . implode(', ', $allowedMethods)
            ];
        }

        // Method-specific validation
        switch ($authMethod) {
            case 'password':
                if (empty($userData['email']) || empty($userData['password'])) {
                    $errors['credentials'] = ['Email and password are required'];
                }
                break;

            case 'oauth':
                if (empty($userData['oauth_provider']) || empty($userData['oauth_token'])) {
                    $errors['oauth'] = ['OAuth provider and token are required'];
                }
                break;

            case 'api_token':
                if (empty($userData['api_token'])) {
                    $errors['api_token'] = ['API token is required'];
                }
                break;
        }

        // Security checks
        $securityValidation = $this->validateAuthenticationSecurity($context);
        if (!$securityValidation->valid) {
            $errors = array_merge($errors, $securityValidation->errors);
        }

        return empty($errors) 
            ? ValidationResult::valid($userData)
            : ValidationResult::invalid($errors);
    }

    /**
     * Validate user contact information.
     *
     * @param array<string, mixed> $userData User data containing contact info
     */
    protected function validateUserContactInfo(array $userData): ValidationResult
    {
        $errors = [];

        // Validate email format and domain
        if (isset($userData['email'])) {
            $email = $userData['email'];
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = ['Invalid email format'];
            } elseif ($this->isDisposableEmail($email)) {
                $errors['email'] = ['Disposable email addresses are not allowed'];
            }
        }

        // Validate phone number
        if (isset($userData['phone'])) {
            $phone = $userData['phone'];
            if (!empty($phone) && !$this->isValidPhoneNumber($phone)) {
                $errors['phone'] = ['Invalid phone number format'];
            }
        }

        // Validate postal code if provided
        if (isset($userData['postal_code'], $userData['country'])) {
            $postalCode = $userData['postal_code'];
            $country = $userData['country'];
            
            if (!$this->isValidPostalCode($postalCode, $country)) {
                $errors['postal_code'] = ['Invalid postal code for selected country'];
            }
        }

        return empty($errors) 
            ? ValidationResult::valid($userData)
            : ValidationResult::invalid($errors);
    }

    /**
     * Validate password strength and security requirements.
     *
     * @param string $password The password to validate
     * @param array<string, mixed> $requirements Custom requirements
     */
    protected function validatePasswordStrength(
        string $password,
        array $requirements = []
    ): ValidationResult {
        $errors = [];

        $defaultRequirements = [
            'min_length' => 8,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_numbers' => true,
            'require_symbols' => false,
            'max_length' => 128,
        ];

        $requirements = array_merge($defaultRequirements, $requirements);

        // Length checks
        if (strlen($password) < $requirements['min_length']) {
            $errors['password'] = ["Password must be at least {$requirements['min_length']} characters"];
        }

        if (strlen($password) > $requirements['max_length']) {
            $errors['password'] = ["Password must not exceed {$requirements['max_length']} characters"];
        }

        // Character class requirements
        if ($requirements['require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
            $errors['password'] = $errors['password'] ?? [];
            $errors['password'][] = 'Password must contain at least one uppercase letter';
        }

        if ($requirements['require_lowercase'] && !preg_match('/[a-z]/', $password)) {
            $errors['password'] = $errors['password'] ?? [];
            $errors['password'][] = 'Password must contain at least one lowercase letter';
        }

        if ($requirements['require_numbers'] && !preg_match('/\d/', $password)) {
            $errors['password'] = $errors['password'] ?? [];
            $errors['password'][] = 'Password must contain at least one number';
        }

        if ($requirements['require_symbols'] && !preg_match('/[^\w\s]/', $password)) {
            $errors['password'] = $errors['password'] ?? [];
            $errors['password'][] = 'Password must contain at least one special character';
        }

        // Check for common weak patterns
        if ($this->isWeakPassword($password)) {
            $errors['password'] = $errors['password'] ?? [];
            $errors['password'][] = 'Password is too common or weak';
        }

        return empty($errors) 
            ? ValidationResult::valid(['password' => $password])
            : ValidationResult::invalid($errors);
    }

    /**
     * Validate user security context and detect suspicious activity.
     *
     * @param array<string, mixed> $userData User data
     */
    private function validateUserSecurity(array $userData): ValidationResult
    {
        $errors = [];

        // Check for SQL injection patterns
        $suspiciousFields = ['name', 'email', 'username'];
        foreach ($suspiciousFields as $field) {
            if (isset($userData[$field]) && $this->containsSuspiciousContent($userData[$field])) {
                $errors['security'] = ['Suspicious content detected'];
                break;
            }
        }

        return empty($errors) 
            ? ValidationResult::valid($userData)
            : ValidationResult::invalid($errors);
    }

    /**
     * Validate authentication security context.
     *
     * @param Context $context The authentication context
     */
    private function validateAuthenticationSecurity(Context $context): ValidationResult
    {
        $errors = [];

        // Check for rate limiting metadata
        $attemptCount = $context->getMetadata('auth_attempt_count', 0);
        if ($attemptCount > 5) {
            $errors['rate_limit'] = ['Too many authentication attempts'];
        }

        // Check for suspicious IP
        $clientIp = $context->getMetadata('client_ip');
        if ($clientIp && $this->isSuspiciousIP($clientIp)) {
            $errors['security'] = ['Authentication from suspicious location'];
        }

        return empty($errors) 
            ? ValidationResult::valid($context->payload)
            : ValidationResult::invalid($errors);
    }

    /**
     * Extract user data from payload.
     *
     * @param mixed $payload The context payload
     * @return array<string, mixed>|null
     */
    private function extractUserData(mixed $payload): ?array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_object($payload)) {
            if (method_exists($payload, 'toArray')) {
                return $payload->toArray();
            }
            return json_decode(json_encode($payload), true);
        }

        return null;
    }

    /**
     * Check if email is from a disposable email provider.
     *
     * @param string $email The email address
     */
    private function isDisposableEmail(string $email): bool
    {
        $disposableDomains = [
            '10minutemail.com', 'guerrillamail.com', 'mailinator.com',
            'tempmail.org', 'yopmail.com', 'throwaway.email'
        ];

        $domain = strtolower(substr(strrchr($email, '@'), 1));
        
        return in_array($domain, $disposableDomains, true);
    }

    /**
     * Validate phone number format.
     *
     * @param string $phone The phone number
     */
    private function isValidPhoneNumber(string $phone): bool
    {
        // Remove all non-digit characters
        $cleaned = preg_replace('/\D/', '', $phone);
        
        // Basic validation: 7-15 digits (international standard)
        return strlen($cleaned) >= 7 && strlen($cleaned) <= 15;
    }

    /**
     * Validate postal code for specific country.
     *
     * @param string $postalCode The postal code
     * @param string $country The country code
     */
    private function isValidPostalCode(string $postalCode, string $country): bool
    {
        $patterns = [
            'US' => '/^\d{5}(-\d{4})?$/',
            'CA' => '/^[A-Z]\d[A-Z] ?\d[A-Z]\d$/',
            'UK' => '/^[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}$/i',
            'DE' => '/^\d{5}$/',
            'FR' => '/^\d{5}$/',
        ];

        $pattern = $patterns[strtoupper($country)] ?? '/^.{3,10}$/'; // Generic fallback
        
        return preg_match($pattern, $postalCode) === 1;
    }

    /**
     * Check if password is weak or common.
     *
     * @param string $password The password to check
     */
    private function isWeakPassword(string $password): bool
    {
        $commonPasswords = [
            'password', '123456', 'qwerty', 'abc123', 'password123',
            'admin', 'letmein', 'welcome', 'monkey', '1234567890'
        ];

        return in_array(strtolower($password), $commonPasswords, true);
    }

    /**
     * Check for suspicious content in user input.
     *
     * @param string $content The content to check
     */
    private function containsSuspiciousContent(string $content): bool
    {
        $suspiciousPatterns = [
            '/union\s+select/i',
            '/drop\s+table/i',
            '/<script/i',
            '/javascript:/i',
            '/on\w+\s*=/i', // Event handlers
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP address is suspicious.
     *
     * @param string $ip The IP address
     */
    private function isSuspiciousIP(string $ip): bool
    {
        // Basic implementation - in production, integrate with threat intelligence
        $knownBadRanges = [
            '127.0.0.1', // Localhost shouldn't authenticate
            '0.0.0.0',   // Invalid IP
        ];

        return in_array($ip, $knownBadRanges, true);
    }
}