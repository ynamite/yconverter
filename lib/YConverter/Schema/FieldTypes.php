<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema;

/**
 * Resolves which YForm field types are actually offerable in the current installation.
 * Core types (provided by YForm itself) are always available; the rest depend on their
 * addon being installed. The preview dropdown and the AI catalogue use available() so an
 * uninstalled type is never offered, proposed, or written.
 */
class FieldTypes
{
    /**
     * type => addon that must be installed for it. Types not listed here are YForm core.
     *
     * @var array<string,string>
     */
    const ADDON_TYPES = [
        'lang_text' => 'yform_lang_fields',
        'lang_textarea' => 'yform_lang_fields',
        'lang_media' => 'yform_lang_fields',
        'custom_link' => 'mform',
        'custom_link_multi' => 'mform',
        'imagelist' => 'mform',
        'color_swatch' => 'mform',
        'medialist' => 'mform',
        'linklist' => 'mform',
    ];

    /**
     * The full catalogue regardless of installed addons.
     *
     * @return array<int,string>
     */
    public static function all()
    {
        return SchemaDetector::allowedTypes();
    }

    /**
     * The catalogue filtered to types whose providing addon is installed and available.
     *
     * @return array<int,string>
     */
    public static function available()
    {
        $available = [];
        foreach (self::all() as $type) {
            $addon = isset(self::ADDON_TYPES[$type]) ? self::ADDON_TYPES[$type] : null;
            if (null === $addon || \rex_addon::get($addon)->isAvailable()) {
                $available[] = $type;
            }
        }
        return $available;
    }
}
