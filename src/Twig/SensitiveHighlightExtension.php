<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtre Twig pour mettre en surbrillance les mots sensibles dans un texte.
 *
 * Usage : {{ message.content|highlight_sensitive(['paiement', 'cash', ...]) }}
 *         {{ message.content|highlight_sensitive(['paiement'], 'mauvais-mot') }}  (highlight aussi un terme libre)
 *
 * Renvoie du HTML safe (les balises de l'utilisateur sont escapées avant insertion des <mark>).
 */
class SensitiveHighlightExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('highlight_sensitive', $this->highlight(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param string[] $keywords
     */
    public function highlight(?string $text, array $keywords = [], string $userSearch = ''): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // Étape 1 : escape pour neutraliser le HTML utilisateur
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Étape 2 : préparer la liste des termes à highlighter (sensible + recherche libre)
        $terms = [];
        foreach ($keywords as $kw) {
            if (is_string($kw) && trim($kw) !== '') {
                $terms[] = trim($kw);
            }
        }
        if ($userSearch !== '' && mb_strlen($userSearch) >= 2) {
            $terms[] = $userSearch;
        }
        if (empty($terms)) {
            return $escaped;
        }

        // Trier par longueur décroissante pour matcher d'abord les plus longs (évite chevauchements)
        usort($terms, static fn($a, $b) => mb_strlen($b) - mb_strlen($a));

        // Construire un regex unique (case insensitive, multibyte)
        $patterns = array_map(static fn($t) => preg_quote($t, '/'), $terms);
        $regex    = '/(' . implode('|', $patterns) . ')/iu';

        return preg_replace_callback(
            $regex,
            static fn(array $m) => '<mark class="msg-highlight">' . $m[0] . '</mark>',
            $escaped,
        ) ?? $escaped;
    }
}
