<?php

namespace Dgtlss\EasyErd\Services;

/** Read literal declarations without loading or executing application PHP. */
class PhpSource
{
    public static function tokens(string $source): array
    {
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    continue;
                }
                $token = $token[1];
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    public static function group(array $tokens, int &$offset): array
    {
        $closing = ['(' => ')', '[' => ']', '{' => '}'][$tokens[$offset]];
        $result = [];
        for ($offset++; $offset < count($tokens) && $tokens[$offset] !== $closing; $offset++) {
            $token = $tokens[$offset];
            if (in_array($token, ['(', '[', '{'])) {
                $result[] = $token;
                array_push($result, ...self::group($tokens, $offset));
                $result[] = $tokens[$offset] ?? '';
            } else {
                $result[] = $token;
            }
        }

        return $result;
    }

    public static function arguments(array $tokens): array
    {
        if (!$tokens) {
            return [];
        }
        $arguments = [[]];
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if ($token === ',') {
                $arguments[] = [];
            } elseif (in_array($token, ['(', '[', '{'])) {
                $argument = [$token, ...self::group($tokens, $i), $tokens[$i] ?? ''];
                array_push($arguments[array_key_last($arguments)], ...$argument);
            } else {
                $arguments[array_key_last($arguments)][] = $token;
            }
        }

        return $arguments;
    }

    public static function argument(array $arguments, int $position, ?string $name = null): ?string
    {
        if ($name !== null) {
            foreach ($arguments as $argument) {
                if (($argument[0] ?? null) === $name && ($argument[1] ?? null) === ':') {
                    return implode('', array_slice($argument, 2));
                }
            }
        }
        $argument = $arguments[$position] ?? [];

        return ($argument[1] ?? null) === ':' ? null : ($argument ? implode('', $argument) : null);
    }

    public static function literal(?string $expression): ?string
    {
        if ($expression === null || count(self::tokens('<?php '.$expression)) !== 1 || !preg_match('/^([\'"])(.*)\1$/s', $expression, $match)) {
            return null;
        }

        return $match[1] === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $match[2])
            : stripcslashes($match[2]);
    }

    public static function calls(array $tokens, string $receiver, string $operator = '->'): array
    {
        $calls = [];
        for ($i = 0; $i < count($tokens) - 3; $i++) {
            if ($tokens[$i] !== $receiver || $tokens[$i + 1] !== $operator || $tokens[$i + 3] !== '(') {
                continue;
            }
            $method = $tokens[$i + 2];
            $i += 3;
            $arguments = self::arguments(self::group($tokens, $i));
            $chain = [];
            while (($tokens[$i + 1] ?? null) === '->' && ($tokens[$i + 3] ?? null) === '(') {
                $chainedMethod = $tokens[$i + 2];
                $i += 3;
                $chain[$chainedMethod] = self::arguments(self::group($tokens, $i));
            }
            $calls[] = ['method' => $method, 'arguments' => $arguments, 'chain' => $chain];
        }

        return $calls;
    }

    public static function methods(array $tokens): array
    {
        $methods = [];
        for ($i = 0; $i < count($tokens) - 2; $i++) {
            if ($tokens[$i] !== 'function' || $tokens[$i + 2] !== '(') {
                continue;
            }
            $name = $tokens[$i + 1];
            $i += 2;
            self::group($tokens, $i);
            while (isset($tokens[++$i]) && !in_array($tokens[$i], ['{', ';'])) {
                // Skip return type declarations.
            }
            if (($tokens[$i] ?? null) === '{') {
                $methods[$name] = self::group($tokens, $i);
            }
        }

        return $methods;
    }

    public static function resolveClass(string $expression, array $tokens): string
    {
        $literal = self::literal($expression);
        if ($literal !== null) {
            return ltrim($literal, '\\');
        }
        $class = preg_replace('/::class$/i', '', $expression);
        if (str_starts_with($class, '\\')) {
            return ltrim($class, '\\');
        }

        $namespace = '';
        $imports = [];
        for ($i = 0; $i < count($tokens); $i++) {
            if (in_array($tokens[$i], ['class', 'return'])) {
                break;
            }
            if ($tokens[$i] === 'namespace') {
                $namespace = $tokens[$i + 1];
            }
            if ($tokens[$i] === 'use') {
                $declaration = [];
                while (isset($tokens[++$i]) && $tokens[$i] !== ';') {
                    $declaration[] = $tokens[$i];
                }
                $prefix = '';
                $group = array_search('{', $declaration, true);
                if ($group !== false) {
                    $prefix = implode('', array_slice($declaration, 0, $group));
                    $declaration = self::group($declaration, $group);
                }
                foreach (self::arguments($declaration) as $importTokens) {
                    if (!$importTokens || in_array($importTokens[0], ['function', 'const'])) {
                        continue;
                    }
                    $import = $prefix.$importTokens[0];
                    $alias = ($importTokens[1] ?? null) === 'as' ? $importTokens[2] : class_basename($import);
                    $imports[strtolower($alias)] = $import;
                }
            }
        }
        $parts = explode('\\', $class, 2);

        return isset($imports[strtolower($parts[0])])
            ? $imports[strtolower($parts[0])].(isset($parts[1]) ? '\\'.$parts[1] : '')
            : ($namespace ? $namespace.'\\' : '').$class;
    }
}
