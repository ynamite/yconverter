<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema\Ai;

/**
 * Optional AI assist for columns the heuristics left at LOW confidence.
 */
interface AiFieldProvider
{
    /**
     * @param array<int,array{name:string,type:string,samples:array<int,string>}> $columns
     * @param array<int,string>                                                    $allowedTypes
     * @param int[]                                                                $clangIds
     *
     * @return array<string,array{typeName:string,params:array,reason:string}> keyed by column name
     */
    public function proposeFields(array $columns, array $allowedTypes, array $clangIds): array;
}
