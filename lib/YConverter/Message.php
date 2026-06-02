<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 * @author Thomas Blum <thomas.blum@yakamara.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace YConverter;

class Message
{
    protected $messages = [];
    protected $entries = [];

    public function addError($value)
    {
        $this->messages[] = \rex_view::error($value);
        $this->entries[] = ['type' => 'error', 'value' => $value];
    }

    public function addInfo($value)
    {
        $this->messages[] = \rex_view::info($value);
        $this->entries[] = ['type' => 'info', 'value' => $value];
    }

    public function addSuccess($value)
    {
        $this->messages[] = \rex_view::success($value);
        $this->entries[] = ['type' => 'success', 'value' => $value];
    }

    public function addWarning($value)
    {
        $this->messages[] = \rex_view::warning($value);
        $this->entries[] = ['type' => 'warning', 'value' => $value];
    }

    public function getAll()
    {
        return implode('', $this->messages);
    }

    /**
     * @return array<int, array{type: string, value: string}>
     */
    public function getEntries()
    {
        return $this->entries;
    }

    /**
     * @param string[] $types
     * @return int
     */
    public function countTypes(array $types)
    {
        $count = 0;
        foreach ($this->entries as $entry) {
            if (in_array($entry['type'], $types, true)) {
                ++$count;
            }
        }
        return $count;
    }
}
