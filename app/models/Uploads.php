<?php
/**
 * \Elabftw\Elabftw\Uploads
 *
 * @author Nicolas CARPi <nicolas.carpi@curie.fr>
 * @copyright 2012 Nicolas CARPi
 * @see http://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */
namespace Elabftw\Elabftw;

use \PDO;
use \Exception;

/**
 * All about the file uploads
 */
class Uploads
{
    /** pdo object */
    private $pdo;

    /**
     * Constructor
     *
     */
    public function __construct()
    {
        $this->pdo = Db::getConnection();
    }

    /**
     * Generate html for the upload form
     *
     * @param int $id
     * @param string $type
     */
    public function buildUploadForm($id, $type)
    {
        $html = "<section class='box'>";
        $html .= "<img src='img/attached.png' class='bot5px'> ";
        $html .= "<h3 style='display:inline'>" . _('Attach a file') . "</h3>";
        $html .= "<form action='app/upload.php' class='dropzone' id='elabftw-dropzone'></form>";
        $html .= "</section>";

        $html .= "<script>
        // we need this to reload the #filesdiv (div displaying uploaded files)
        var type = '" . $type . "';
        if (type == 'items') {
            type = 'database';
        }
        var item_id = '" . $id . "';

        // config for dropzone, id is camelCased.
        Dropzone.options.elabftwDropzone = {
            // i18n message to user
            dictDefaultMessage: '" . _('Drop files here to upload') . "',
            maxFilesize: '" . Tools::returnMaxUploadSize() . "', // MB
            init: function() {

                // add additionnal parameters (id and type)
                this.on('sending', function(file, xhr, formData) {
                    formData.append('item_id', '" . $id . "');
                    formData.append('type', '" . $type . "');
                });

                // once it is done
                this.on('complete', function(answer) {
                    // check the answer we get back from app/uploads.php
                    if (answer.xhr.responseText != 0) {
                        alert('Upload failed: ' + answer.xhr.responseText);
                    }
                    // reload the #filesdiv once the file is uploaded
                    if (this.getUploadingFiles().length === 0 && this.getQueuedFiles().length === 0) {
                        $('#filesdiv').load(type + '.php?mode=edit&id=' + item_id + ' #filesdiv', function() {
                            // make the comment zone editable (fix issue #54)
                            $('.thumbnail p.editable').editable('app/editinplace.php', {
                             indicator : 'Saving...',
                             id   : 'id',
                             name : 'filecomment',
                             submit : 'Save',
                             cancel : 'Cancel',
                             style : 'display:inline'
                            });
                        });
                    }
                });
            }
        };
        </script>";
        return $html;
    }

    /**
     * Read infos about an upload
     *
     * @param int $id ID of the item
     * @param string $type
     * @return array
     */
    public function read($id, $type)
    {
        // Check that the item we view has attached files
        $sql = "SELECT * FROM uploads WHERE item_id = :id AND type = :type";
        $req = $this->pdo->prepare($sql);
        $req->bindParam(':id', $id);
        $req->bindParam(':type', $type);
        $req->execute();

        return $req->fetchAll();
    }

    /**
     * Generate HTML for displaying uploaded files
     *
     * @param int $id Id of the item
     * @param string $mode edit or view
     * @param string $type type of upload
     * @return string html
     */
    public function buildUploads($id, $mode, $type)
    {
        $uploadsArr = $this->read($id, $type);

        $count = count($uploadsArr);
        if ($count < 1) {
            // return the empty div so it can be reloaded upon file upload
            return "<div id='filesdiv'></div>";
        }

        // this is for the plural of the ngettext function below
        if ($count > 1) {
            $count = 2;
        }

        // begin HTML build
        $html = "<div id='filesdiv'>";
        $html .= "<div class='box'>";
        $html .= "<img src='img/attached.png' class='bot5px'> <h3 style='display:inline'>" .
            ngettext('Attached file', 'Attached files', $count) . "</h3>";
        $html .= "<div class='row'>";
        foreach ($uploadsArr as $upload) {
            $html .= "<div class='col-md-4 col-sm-6'>";
            $html .= "<div class='thumbnail'>";
            // show the delete button only in edit mode, not in view mode
            if ($mode === 'edit') {
                $html .= "<a class='align_right' href='app/delete_file.php?id=" . $upload['id'] . "&type=" .
                    $upload['type'] . "&item_id=" . $upload['item_id'] .
                    "' onClick=\"return confirm('Delete this file ?');\">";
                $html .= "<img src='img/small-trash.png' title='delete' alt='delete' /></a>";
            } // end if it is in edit mode

            // get file extension
            $ext = Tools::getExt($upload['real_name']);
            $filepath = 'uploads/' . $upload['long_name'];
            $thumbpath = $filepath . '_th.jpg';

            // list of extensions with a corresponding img/thumb-*.png image
            $commonExtensions = array('avi', 'csv', 'doc', 'docx', 'mov', 'pdf', 'ppt', 'rar', 'xls', 'xlsx', 'zip');

            // list of extensions understood by 3Dmol.js
            $molExtensions = array('pdb', 'sdf', 'mol2', 'mmcif', 'cif');

            // Make thumbnail only if it isn't done already
            if (!file_exists($thumbpath)) {
                make_thumb($filepath, $ext, $thumbpath, 100);
            }

            // only display the thumbnail if the file is here
            if (file_exists($thumbpath) && preg_match('/(jpg|jpeg|png|gif)$/i', $ext)) {
                // we add rel='gallery' to the images for fancybox to display it as an album
                // (possibility to go next/previous)
                $html .= "<a href='app/download.php?f=" . $upload['long_name'] . "' class='fancybox' rel='gallery' ";
                if ($upload['comment'] != 'Click to add a comment') {
                    $html .= "title='" . $upload['comment'] . "'";
                }
                $html .= "><img class='thumb' src='" . $thumbpath . "' alt='thumbnail' /></a>";

            // not an image
            } elseif (in_array($ext, $commonExtensions)) {
                $html .= "<img class='thumb' src='img/thumb-" . $ext . ".png' alt='' />";

            // special case for mol files, only in view mode
            } elseif ($ext === 'mol' && $_SESSION['prefs']['chem_editor'] && $mode === 'view') {
                // we need to escape \n in the mol file or we get unterminated string literal error in JS
                $mol = str_replace("\n", "\\n", file_get_contents($filepath));
                $html .= "<div class='center'><script>
                      showMol('" . $mol . "');
                      </script></div>";

            // if this is something 3Dmol.js can handle
            } elseif (in_array($ext, $molExtensions)) {
                $molviewer = new MolViewer($upload['id'], $filepath);
                $html .= $molviewer->getViewerDiv();

            } else {
                // uncommon extension without a nice image to display
                $html .= "<img class='thumb' src='img/thumb.png' alt='' />";
            }

            // now display the name + comment with icons
            $html .= "<div class='caption'><img src='img/attached.png' class='bot5px' alt='attached' /> ";
            $html .= "<a href='app/download.php?f=" . $upload['long_name'] .
                "&name=" . $upload['real_name'] . "' target='_blank'>" . $upload['real_name'] . "</a>";
            $html .= "<span class='smallgray' style='display:inline'> " .
                Tools::formatBytes(filesize('uploads/' . $upload['long_name'])) . "</span><br>";
            // if we are in view mode, we don't show the comment if it's the default text
            // this is to avoid showing 'Click to add a comment' where in fact you can't click to add a comment because
            // your are in view mode

            if ($mode === 'edit' || ($upload['comment'] != 'Click to add a comment')) {
                $comment = "<img src='img/comment.png' class='bot5px' alt='comment' />
                            <p class='editable inline' id='filecomment_" . $upload['id'] . "'>" .
                stripslashes($upload['comment']) . "</p>";
                $html .= $comment;
            }
            $html .= "</div></div></div>";
        } // end foreach
        $html .= "</div></div></div>";

        // add fancy stuff in edit mode
        if ($mode === 'edit') {
            $html .= "<script>
                $('.thumbnail').on('mouseover', '.editable', function(){
                $('.thumbnail p.editable').editable('app/editinplace.php', {
                 tooltip : 'Click to edit',
                 indicator : 'Saving...',
                 name : 'filecomment',
                 submit : 'Save',
                 cancel : 'Cancel',
                 style : 'display:inline'
                });
            });
            $(document).ready(function() {
                // we use fancybox to display thumbnails
                $('a.fancybox').fancybox();
            });
            </script>";
        }
        return $html;
    }
}
