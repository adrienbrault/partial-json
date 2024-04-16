<?php

namespace GregHunt\PartialJson;

use JsonException;

class JsonParser
{
    /**
     * @var array<array-key, string>
     */
    private $parsers = [];

    /**
     * @var null|callable(string, mixed, string)s
     */
    private $onExtraToken;

    /**
     * @param null|callable(string, mixed, string): void $onExtraToken
     */
    public function __construct($onExtraToken = null)
    {
        $this->parsers = array_fill_keys([' ', "\r", "\n", "\t"], 'parseSpace');
        $this->parsers['['] = 'parseArray';
        $this->parsers['{'] = 'parseObject';
        $this->parsers['"'] = 'parseString';
        $this->parsers['t'] = 'parseTrue';
        $this->parsers['f'] = 'parseFalse';
        $this->parsers['n'] = 'parseNull';

        foreach (str_split('0123456789.-') as $char) {
            $this->parsers[$char] = 'parseNumber';
        }

        $this->onExtraToken = $onExtraToken;
    }

    public function parse(string $s, bool $associative = true): mixed
    {
        if (strlen($s) >= 1) {
            try {
                return json_decode($s, $associative, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                list($data, $reminding) = $this->parseAny($s, $e);

                if (null !== $this->onExtraToken && $reminding) {
                    call_user_func($this->onExtraToken, $s, $data, $reminding);
                }
                return $data;
            }
        } else {
            return json_decode('{}', $associative);
        }
    }

    /**
     * @return array{0: mixed, 1: string}
     */
    private function parseAny(string $s, JsonException $e)
    {
        if (!$s) {
            throw $e;
        }
        $parser = $this->parsers[$s[0]] ?? null;
        if (!$parser) {
            throw $e;
        }
        return $this->{$parser}($s, $e);
    }

    /**
     * @return array{0: mixed, 1: string}
     */
    private function parseSpace(string $s, JsonException $e)
    {
        return $this->parseAny(trim($s), $e);
    }

    /**
     * @return array{0: mixed, 1: string}
     */
    private function parseArray(string $s, JsonException $e)
    {
        $s = substr($s, 1);  // skip starting '['
        $acc = [];
        $s = trim($s);
        while ($s) {
            if ($s[0] == ']') {
                $s = substr($s, 1);  // skip ending ']'
                break;
            }
            list($res, $s) = $this->parseAny($s, $e);
            $acc[] = $res;
            $s = trim($s);
            if (strpos($s, ',') === 0) {
                $s = substr($s, 1);
                $s = trim($s);
            }
        }
        return [$acc, $s];
    }

    /**
     * @return array{0: mixed, 1: string}
     */
    private function parseObject(string $s, JsonException $e)
    {
        $s = substr($s, 1);  // skip starting '{'
        $acc = [];
        $s = trim($s);
        while ($s) {
            if ($s[0] == '}') {
                $s = substr($s, 1);  // skip ending '}'
                break;
            }
            list($key, $s) = $this->parseAny($s, $e);
            $s = trim($s);

            if (!$s || $s[0] == '}') {
                $acc[$key] = null;
                break;
            }

            if ($s[0] != ':') {
                throw $e;
            }

            $s = substr($s, 1);  // skip ':'
            $s = trim($s);

            if (!$s || in_array($s[0], [',', '}'])) {
                $acc[$key] = null;
                if (strpos($s, ',') === 0) {
                    $s = substr($s, 1);
                }
                break;
            }

            list($value, $s) = $this->parseAny($s, $e);
            $acc[$key] = $value;
            $s = trim($s);
            if (strpos($s, ',') === 0) {
                $s = substr($s, 1);
                $s = trim($s);
            }
        }
        return [$acc, $s];
    }

    /**
     * @return array{0: mixed, 1: string}
     */
    private function parseString(string $s, JsonException $e)
    {
        $end = strpos($s, '"', 1);
        while ($end !== false && $s[$end - 1] == '\\') {  // Handle escaped quotes
            $end = strpos($s, '"', $end + 1);
        }
        if ($end === false) {
            // Return the incomplete string without the opening quote
            return [substr($s, 1), ""];
        }
        $strVal = substr($s, 0, $end + 1);
        $s = substr($s, $end + 1);
        return [json_decode($strVal), $s];
    }

    /**
     * @return array{0: mixed, 1: string}
     */
    private function parseNumber(string $s, JsonException $e)
    {
        $i = 0;
        while ($i < strlen($s) && strpos('0123456789.-', $s[$i]) !== false) {
            $i++;
        }
        $numStr = substr($s, 0, $i);
        $s = substr($s, $i);
        if ($numStr == '' || substr($numStr, -1) == '.' || substr($numStr, -1) == '-') {
            // Return the incomplete number as is
            return [$numStr, ""];
        }
        if (strpos($numStr, '.') !== false || strpos($numStr, 'e') !== false || strpos($numStr, 'E') !== false) {
            $num = floatval($numStr);
        } else {
            $num = intval($numStr);
        }
        return [$num, $s];
    }

    /**
     * @return array{0: mixed, 1: string}
     */
    private function parseTrue(string $s, JsonException $e)
    {
        if (substr($s, 0, 4) == 'true') {
            return [true, substr($s, 4)];
        }
        throw $e;
    }

    /**
     * @return array{0: mixed, 1: string}
     */
    private function parseFalse(string $s, JsonException $e)
    {
        if (substr($s, 0, 5) == 'false') {
            return [false, substr($s, 5)];
        }
        throw $e;
    }

    /**
     * @return array{0: mixed, 1: string}
     */
    private function parseNull(string $s, JsonException $e): array
    {
        if (substr($s, 0, 4) == 'null') {
            return [null, substr($s, 4)];
        }
        throw $e;
    }
}
