<?php

declare(strict_types=1);

namespace Gurb;

/**
 * Permissions the SDK knows by name.
 *
 * DELIBERATELY NOT AN ENUM, unlike CommunityRole next door. The two look like
 * the same kind of thing and are not:
 *
 *   - Roles are a closed set of three. The platform will not quietly grow a
 *     fourth, and if it did, that would be a breaking change worth a release.
 *   - Permissions are a *catalogue*. It grows — the platform is at 48 and
 *     counting — and a new one appears whenever a new module ships.
 *
 * If this were an enum, using a permission added last Tuesday would require
 * upgrading the SDK first, and every method here would take an argument that
 * cannot express the value the server is expecting. So permissions are plain
 * strings, and these constants exist for autocomplete and typo-safety in the
 * common cases, not as a required vocabulary:
 *
 *     $gurb->members->updatePermissions($id, grant: [Permission::CREATE_EVENT]);
 *     $gurb->members->updatePermissions($id, grant: ['SOMETHING_NEW']);  // also fine
 *
 * The server owns the real answer either way and rejects a name it does not
 * recognise, so nothing is lost by being permissive here.
 */
final class Permission
{
    // ─── Content ─────────────────────────────────────────────────────────────
    public const CREATE_POST = 'CREATE_POST';
    public const EDIT_ANY_POST = 'EDIT_ANY_POST';
    public const DELETE_ANY_POST = 'DELETE_ANY_POST';
    public const CREATE_EVENT = 'CREATE_EVENT';
    public const EDIT_ANY_EVENT = 'EDIT_ANY_EVENT';
    public const DELETE_ANY_EVENT = 'DELETE_ANY_EVENT';
    public const CREATE_BLOG = 'CREATE_BLOG';
    public const EDIT_ANY_BLOG = 'EDIT_ANY_BLOG';
    public const DELETE_ANY_BLOG = 'DELETE_ANY_BLOG';
    public const CREATE_ALBUM = 'CREATE_ALBUM';
    public const EDIT_ANY_ALBUM = 'EDIT_ANY_ALBUM';
    public const DELETE_ANY_ALBUM = 'DELETE_ANY_ALBUM';
    public const UPLOAD_MEDIA = 'UPLOAD_MEDIA';

    // ─── Membership ──────────────────────────────────────────────────────────
    public const INVITE_MEMBERS = 'INVITE_MEMBERS';
    public const REMOVE_MEMBERS = 'REMOVE_MEMBERS';
    public const APPROVE_JOIN_REQUESTS = 'APPROVE_JOIN_REQUESTS';
    public const ASSIGN_ROLES = 'ASSIGN_ROLES';
    public const MANAGE_PERMISSIONS = 'MANAGE_PERMISSIONS';

    // ─── Moderation ──────────────────────────────────────────────────────────
    public const MODERATE_CONTENT = 'MODERATE_CONTENT';
    public const RESTRICT_MEMBERS = 'RESTRICT_MEMBERS';
    public const VIEW_REPORTS = 'VIEW_REPORTS';

    // ─── Community ───────────────────────────────────────────────────────────
    public const EDIT_COMMUNITY_SETTINGS = 'EDIT_COMMUNITY_SETTINGS';
    public const MANAGE_API_KEYS = 'MANAGE_API_KEYS';
    public const VIEW_ANALYTICS = 'VIEW_ANALYTICS';

    /**
     * The names above, as a list.
     *
     * For reference and for building a picker UI — NOT for validating input.
     * Checking a caller's permission against this list would reintroduce exactly
     * the coupling the class comment argues against: a permission the server
     * accepts would be refused locally because this SDK is a version behind.
     *
     * @var list<string>
     */
    public const KNOWN_PERMISSIONS = [
        self::CREATE_POST,
        self::EDIT_ANY_POST,
        self::DELETE_ANY_POST,
        self::CREATE_EVENT,
        self::EDIT_ANY_EVENT,
        self::DELETE_ANY_EVENT,
        self::CREATE_BLOG,
        self::EDIT_ANY_BLOG,
        self::DELETE_ANY_BLOG,
        self::CREATE_ALBUM,
        self::EDIT_ANY_ALBUM,
        self::DELETE_ANY_ALBUM,
        self::UPLOAD_MEDIA,
        self::INVITE_MEMBERS,
        self::REMOVE_MEMBERS,
        self::APPROVE_JOIN_REQUESTS,
        self::ASSIGN_ROLES,
        self::MANAGE_PERMISSIONS,
        self::MODERATE_CONTENT,
        self::RESTRICT_MEMBERS,
        self::VIEW_REPORTS,
        self::EDIT_COMMUNITY_SETTINGS,
        self::MANAGE_API_KEYS,
        self::VIEW_ANALYTICS,
    ];

    private function __construct()
    {
    }
}
