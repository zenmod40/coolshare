<?php
/**
 * CoolShare - Balises de partage (Open Graph, cartes X) pour PrestaShop
 *
 * @author    ZM40 — Nicolas Michaud (Magic Garden)
 * @copyright 2026 Nicolas Michaud — ZM40 / Magic Garden
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License version 3.0
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Un refus de CoolShareApi. getMessage() est un CODE stable en snake_case
 * (unknown_entity, not_found, bad_image, image_too_large, bad_restore_token,
 * save_failed) : l'appelant le traduit, il ne l'affiche pas tel quel.
 */
class CoolShareException extends Exception
{
}
