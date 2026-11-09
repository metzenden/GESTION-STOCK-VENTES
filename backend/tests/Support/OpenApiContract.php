<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Assert;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Throwable;

/**
 * Vérifie qu'une réponse de l'API respecte le contrat openapi/openapi.yaml (D-035).
 */
final class OpenApiContract
{
    private static ?ResponseValidator $validator = null;

    /** @param string $path chemin du contrat, relatif au serveur /api/v1 (ex. « /products/{id} ») */
    public static function assertResponseMatches(TestResponse $response, string $method, string $path): void
    {
        $factory = new Psr17Factory;
        $psrResponse = (new PsrHttpFactory($factory, $factory, $factory, $factory))->createResponse($response->baseResponse);

        try {
            self::validator()->validate(new OperationAddress($path, strtolower($method)), $psrResponse);
        } catch (Throwable $e) {
            $previous = $e->getPrevious()?->getMessage();
            Assert::fail("Réponse non conforme au contrat pour {$method} {$path} : {$e->getMessage()}".($previous ? " ({$previous})" : ''));
        }

        Assert::assertTrue(true);
    }

    private static function validator(): ResponseValidator
    {
        return self::$validator ??= (new ValidatorBuilder)
            ->fromYamlFile(dirname(base_path()).'/openapi/openapi.yaml')
            ->getResponseValidator();
    }
}
