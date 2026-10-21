<?php

namespace App\Support\Docs;

use App\Support\ApiResponse;
use Dedoc\Scramble\Infer\Extensions\Event\StaticMethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\StaticMethodReturnTypeExtension;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\BooleanType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;
use Illuminate\Http\JsonResponse;

/**
 * Teaches Scramble the shape of ApiResponse::success()/created()/noContent() so the generated
 * OpenAPI document shows the real envelope with the typed `data` payload, instead of an
 * opaque JsonResponse.
 */
class ApiResponseTypeExtension implements StaticMethodReturnTypeExtension
{
    public function shouldHandle(string $name): bool
    {
        return $name === ApiResponse::class;
    }

    public function getStaticMethodReturnType(StaticMethodCallEvent $event): ?Type
    {
        return match ($event->getName()) {
            'success' => $this->envelope(
                $event->getArg('data', 0, new NullType),
                $event->getArg('message', 1, new LiteralStringType('Success')),
                $event->getArg('status', 2, new LiteralIntegerType(200)),
            ),
            'created' => $this->envelope(
                $event->getArg('data', 0, new NullType),
                $event->getArg('message', 1, new LiteralStringType('Created')),
                new LiteralIntegerType(201),
            ),
            'noContent' => $this->envelope(new NullType, $event->getArg('message', 0, new LiteralStringType('Success')), new LiteralIntegerType(200)),
            default => null,
        };
    }

    private function envelope(Type $data, Type $message, Type $status): Type
    {
        $body = new KeyedArrayType([
            new ArrayItemType_('status', new BooleanType),
            new ArrayItemType_('statusCode', $status instanceof LiteralIntegerType ? $status : new IntegerType),
            new ArrayItemType_('message', $message instanceof LiteralStringType ? new StringType : $message),
            new ArrayItemType_('data', $data instanceof NullType ? new KeyedArrayType([]) : $data),
            new ArrayItemType_('meta', new ArrayType),
            new ArrayItemType_('requestId', new Union([new StringType, new NullType])),
        ]);

        return new Generic(JsonResponse::class, [$body, $status, new ArrayType]);
    }
}
