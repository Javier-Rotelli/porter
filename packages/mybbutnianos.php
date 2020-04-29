<?php
/**
 * MyBB exporter tool.
 *
 * @copyright 2009-2018 Vanilla Forums Inc.
 * @license GNU GPL2
 * @package VanillaPorter
 * @see functions.commandline.php for command line usage.
 */

$supported['utnianos'] = array('name' => 'MyBB Utnianos', 'prefix' => 'mybb_');
$supported['utnianos']['features'] = array(
    'Comments' => 1,
    'Discussions' => 1,
    'Users' => 1,
    'Categories' => 1,
    'Roles' => 1,
    'Passwords' => 1,
    'Avatars' => 1,
    'Bookmarks' => 1,
    'Attachments' => 1,
);

class Utnianos extends ExportController {
    /**
     * You can use this to require certain tables and columns be present.
     *
     * @var array Required tables => columns
     */
    protected $sourceTables = array(
        'forums' => array(),
        'posts' => array(),
        'threads' => array(),
        'users' => array(),
    );

    /**
     * Main export process.
     *
     * @param ExportModel $ex
     * @see $_Structures in ExportModel for allowed destination tables & columns.
     */
    public function forumExport($ex) {

        $characterSet = $ex->getCharacterSet('posts');
        if ($characterSet) {
            $ex->characterSet = $characterSet;
        }

        // Reiterate the platform name here to be included in the porter file header.
        $ex->beginExport('', 'MyBB');

        // User.
        $user_Map = array(
            'uid' => 'UserID',
            'username' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'avatar' => 'Photo',
            'regdate2' => 'DateInserted',
            'regdate3' => 'DateFirstVisit',
            'email' => 'Email',
        );
        $ex->exportTable('User', "
         select u.*,
            FROM_UNIXTIME(regdate) as regdate2,
            FROM_UNIXTIME(regdate) as regdate3,
            FROM_UNIXTIME(lastactive) as DateLastActive,
            concat(password,':', salt) as Password,
            uf.fid2 as About,
            'mybb' as HashMethod
         from :_users u
         inner join :userfields uf on uid = ufid
         ", $user_Map);

        // Role.
        $role_Map = array(
            'gid' => 'RoleID',
            'title' => 'Name',
            'description' => 'Description',
        );
        $ex->exportTable('Role', "
         select *
         from :_usergroups", $role_Map);

        // User Role.
        $userRole_Map = array(
            'uid' => 'UserID',
            'usergroup' => 'RoleID',
        );
        $ex->exportTable('UserRole', "
         select u.uid, u.usergroup
         from :_users u", $userRole_Map);

        // Category.
        $category_Map = array(
            'fid' => 'CategoryID',
            'pid' => 'ParentCategoryID',
            'disporder' => 'Sort',
            'name' => 'Name',
            'description' => 'Description',
        );
        $ex->exportTable('Category', "
         select *
         from :_forums f
         ", $category_Map);

        // Discussion.
        $discussion_Map = array(
            'tid' => 'DiscussionID',
            'fid' => 'CategoryID',
            'uid' => 'InsertUserID',
            'subject' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'views' => 'CountViews',
            'replies' => 'CountComments',
        );
        $ex->exportTable('Discussion', "
         select *,
            FROM_UNIXTIME(dateline) as DateInserted,
            'BBCode' as Format
         from :_threads t", $discussion_Map);


        // Media
        $media_Map = array(
            'aid' => 'MediaID',
            'pid' => 'ForeignID',
            'uid' => 'InsertUserId',
            'filesize' => 'Size',
            'filename' => 'Name',
            'height' => 'ImageHeight',
            'width' => 'ImageWidth',
            'filetype' => 'Type',
            'thumb_width' => array('Column' => 'ThumbWidth', 'Filter' => array($this, 'filterThumbnailData')),
        );
        $ex->exportTable('Media', "
            select a.*,
                600 as thumb_width,
                concat('attachments/', a.thumbnail) as ThumbPath,
                concat('attachments/', a.attachname) as Path,
                'Comment' as ForeignTable
            from :_attachments a
            where a.pid > 0
        ", $media_Map);

        // Comment.
        $comment_Map = array(
            'pid' => 'CommentID',
            'tid' => 'DiscussionID',
            'uid' => 'InsertUserID',
            'message' => array('Column' => 'Body'),
        );
        $ex->exportTable('Comment', "
         select p.*,
            FROM_UNIXTIME(dateline) as DateInserted,
            'BBCode' as Format
         from :_posts p", $comment_Map);

        // UserDiscussion.
        $userDiscussion_Map = array(
            'tid' => 'DiscussionID',
            'uid' => 'UserID',
        );
        $ex->exportTable('UserDiscussion', "
         select *,
            1 as Bookmarked
         from :_threadsubscriptions t", $userDiscussion_Map);

        // Tag.
        $tagMap = array(
            'FullNameToName' => array('Column' => 'Name', 'Filter' => 'formatUrl')
        );
        $ex->exportTable('Tag', "
                SELECT
                    IdMateria as TagID,
                    Nombre as FullName,
                       COALESCE(NULLIF(Abreviatura,''), Nombre) as FullNameToName
                FROM utnianos_materias
                UNION SELECT 1001,'Parciales', 'Parciales'
                UNION SELECT 1002, 'Finales', 'Finales'
                UNION SELECT 1003, 'Trabajo practico', 'Trabajo practico'
                UNION SELECT 1004, 'Apuntes y Guias', 'Apuntes y Guias'
                UNION SELECT 1005, 'Libro', 'Libro'
                UNION SELECT 1006, 'Profesores', 'Profesores'
                UNION SELECT 1007, 'Ejercicios', 'Ejercicios'
                UNION SELECT 1008, 'Dudas y recomendaciones', 'Dudas y recomendaciones'
                UNION SELECT 1009, 'Consultas administrativas', 'Consultas administrativas'
                UNION SELECT 1010, 'Otro', 'Otro'
                UNION SELECT 1011, 'Guias CEIT', 'Guias CEIT'
            ",$tagMap);

        // TagDiscussion.
        $ex->query("
            create table tagsDisc
                select * from (
                    select
                        tipo_aporte + 1000 as TagID,
                        tid as DiscussionID
                    from
                        :_threadfields_data
                    where tipo_aporte <> '' and tipo_aporte not like \"%\\n%\"
                    union
                    select
                        materia as TagID,
                        tid as DiscussionID
                    from
                        :_threadfields_data
                    where materia <> '' and materia not like \"%\\n%\"
                    ) as alltags");

        $to = $ex->query("
            select * from (
                select
                    tipo_aporte + 1000 as TagID,
                    tid as DiscussionID
                from
                    :_threadfields_data
                where tipo_aporte <> '' and tipo_aporte like \"%\\n%\"
                union
                select
                    materia as TagID,
                    tid as DiscussionID
                from
                    :_threadfields_data
                where materia <> '' and materia like \"%\\n%\"
            ) as alltags
            ");
         if (is_resource($to)) {
             while ($row = $to->nextResultRow()) {
                 $tags = explode("\n",$row['TagID']);
                 $discussion = $row['DiscussionID'];
                 $toIns = '';
                 foreach ($tags as $tagID) {
                     $toIns .= "($tagID,$discussion),";
                 }
                 $toIns = trim($toIns, ',');

                 $ex->query("insert tagsDisc (TagID, DiscussionID) values $toIns");
             }
         }
        $ex->exportTable('TagDiscussion', 'select distinct * from tagsDisc');
        $ex->query('drop table tagsDisc');

        // Carrera del usuario
        $ex->exportTable('UserMeta', "
            select
                ufid as UserID,
                'Profile.Carrera' as Name,
                fid5 as Value
            from mybb_userfields
            union
            select
                ufid as UserID,
                'Profile.Sede' as Name,
                fid6 as Value
            from mybb_userfields"
        );

        $ex->endExport();
    }

    /**
     * Filter used by $Media_Map to replace value for ThumbPath and ThumbWidth when the file is not an image.
     *
     * @access public
     * @see ExportModel::_exportTable
     *
     * @param string $value Current value
     * @param string $field Current field
     * @param array $row Contents of the current record.
     * @return string|null Return the supplied value if the record's file is an image. Return null otherwise
     */
    public function filterThumbnailData($value, $field, $row) {
        if (!empty($row['thumbnail'])) {
            return $value;
        } else {
            return 0;
        }
    }
}

// Closing PHP tag required. (make.php)
?>
