<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Crud\Support;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * 构造 ValidationException，不依赖 Facade / 容器（包测试与 CLI 可用）。
 */
final class CrudValidation
{
    /**
     * @param  array<string, list<string>|string>  $messages
     */
    public static function exception(array $messages): ValidationException
    {
        $translator = new Translator(new ArrayLoader(), 'en');
        $validator = new Validator($translator, [], []);

        foreach ($messages as $key => $value) {
            foreach ((array) $value as $message) {
                $validator->errors()->add($key, $message);
            }
        }

        return new ValidationException($validator);
    }

    /**
     * @param  array<string, list<string>|string>  $messages
     *
     * @throws ValidationException
     */
    public static function throw(array $messages): never
    {
        throw self::exception($messages);
    }
}
