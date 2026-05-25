# Axell Core #
**Contributors:** [axellhydrosystems](https://profiles.wordpress.org/axellhydrosystems/)  
**Tags:** axell, hydrosystems, core  
**Requires at least:** 6.4  
**Tested up to:** 6.8  
**Requires PHP:** 8.1  
**Stable tag:** 0.2.8  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  

Core functionality for Axell Hydrosystems.

## Description ##

Core functionality for Axell Hydrosystems WordPress sites.

## Changelog ##

### 0.2.8 ###
* Make selfdirectory optional — plugin activates without submodule; production deploys still include it.
* Centralize allowed MIME types in axellcore_allowed_mimes() with axellcore_ filter.
* Add axellcore_blocked_exts() hard blocklist applied after filter to prevent executable uploads.
* Restrict CAD uploads to Editor+ via axellcore_current_user_can_upload_cad().
* Validate uploads by MIME (finfo) + extension match instead of magic bytes.
* Add axellcore_upload_cad_capability filter to adjust required capability.
* Fix .rfa MIME to application/x-ole-storage (PHP finfo detection).
* Add SVG icons for .dwg, .skp and .rfa following WordPress monochrome style.
* Inject icons via wp_prepare_attachment_for_js filter.
* Fix translate(-50%, -70%) alignment for image/* CAD icons in media library.
* Exclude image/vnd.dwg (.dwg) from image pickers (featured image, etc.) via ajax_query_attachments_args.

### 0.2.7 ###
* Release 0.2.7 (2026-05-17).

### 0.2.6 ###
* Release 0.2.6 (2026-05-17).

### 0.2.5 ###
* Release 0.2.5 (2026-05-17).

### 0.2.4 ###
* Release 0.2.4 (2026-05-17).

### 0.2.3 ###
* Release 0.2.3 (2026-05-17).

### 0.2.2 ###
* Release 0.2.2 (2026-05-16).

### 0.2.1 ###
* Fix TypeError when $mimes is null in wp_check_filetype_and_ext filter.

### 0.2.0 ###
* Add MIME type support for SketchUp (.skp), AutoCAD (.dwg) and Autodesk Revit (.rfa) uploads.

### 0.1.0 ###
* Initial release.
