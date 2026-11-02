<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

/**
 * Every node and mark the editor can make, for the design page (decision
 * 0205). Sample text in English, like the examples of the base theme.
 */
final class RichTextSample
{
    public const array DOCUMENT = ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['rank' => 1], 'content' => [['type' => 'text', 'text' => 'A heading in the text']]],
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'A paragraph with '],
            ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'bold'],
            ['type' => 'text', 'text' => ', '],
            ['type' => 'text', 'marks' => [['type' => 'italic']], 'text' => 'italic'],
            ['type' => 'text', 'text' => ', '],
            ['type' => 'text', 'marks' => [['type' => 'underline']], 'text' => 'underlined'],
            ['type' => 'text', 'text' => ' and '],
            ['type' => 'text', 'marks' => [['type' => 'strike']], 'text' => 'struck'],
            ['type' => 'text', 'text' => ' words, '],
            ['type' => 'text', 'marks' => [['type' => 'code']], 'text' => 'code'],
            ['type' => 'text', 'text' => ', H'],
            ['type' => 'text', 'marks' => [['type' => 'subscript']], 'text' => '2'],
            ['type' => 'text', 'text' => 'O, m'],
            ['type' => 'text', 'marks' => [['type' => 'superscript']], 'text' => '2'],
            ['type' => 'text', 'text' => ' and '],
            ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => '#fb-design-richtext']]], 'text' => 'a link'],
            ['type' => 'text', 'text' => '.'],
            ['type' => 'hardBreak'],
            ['type' => 'text', 'text' => 'A second line after a line break.'],
        ]],
        ['type' => 'heading', 'attrs' => ['rank' => 2], 'content' => [['type' => 'text', 'text' => 'A subheading']]],
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A point of a list']]]]],
            ['type' => 'listItem', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A point with points below it']]],
                ['type' => 'bulletList', 'content' => [
                    ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A point below']]]]],
                ]],
            ]],
        ]],
        ['type' => 'heading', 'attrs' => ['rank' => 3], 'content' => [['type' => 'text', 'text' => 'A small heading']]],
        ['type' => 'orderedList', 'content' => [
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First step']]]]],
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second step']]]]],
        ]],
        ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A quote, set apart from the text around it.']]]]],
        ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => "echo 'A block of code';"]]],
        ['type' => 'horizontalRule'],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A paragraph after a dividing line.']]],
    ]];
}
