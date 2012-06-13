<?php

// This file is part of the EQUELLA Moodle Integration - https://github.com/equella/moodle-repository-legacy
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

require_once($CFG->dirroot.'/mod/equella/common/lib.php');
require_once($CFG->dirroot.'/mod/equella/common/soap.php');

class repository_equella extends repository {

	private $perpage = 12;		// Currently shows 4 results per row, so choose a multiple of that.

	public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()) {
		$this->keywords = optional_param('equella_search', '', PARAM_RAW);
		parent::__construct($repositoryid, $context, $options);
	}

	public function print_login($ajax = true) {
		$search = new stdClass();
		$search->type = 'text';
		$search->id   = 'equella_search';
		$search->name = 'equella_search';
		$search->label = get_string('search', 'repository_equella').': ';

		$ret = array();
		$ret['login'] = array($search);
		$ret['login_btn_label'] = get_string('search');
		return $ret;
	}

	public function search($keywords, $page) {
		return $this->getResults('', $page, $keywords);
	}

	public function get_listing($path = '', $page = 1) {
		return $this->getResults($path, $page);
	}

	private function getResults($path = '', $page = 1, $keywords = '') {
	
		$ret = array();
		$ret['dynload'] = true;
		$ret['nologin'] = true;
		$ret['manage'] = equella_appendtoken(equella_full_url(''));
		$ret['path'] = array(array(
			'name' => get_string('breadcrumb', 'repository_equella'),
			'path' => ''
		));

		if( empty($path) ) {
			$this->getSearchResults($ret, $page, $keywords);
		} else {
			list($uuid, $version) = split('/', $path);
			$this->getAttachmentListing($ret, $uuid, $version);
		}

		return $ret;
	}

	private function getAcceptedTypes() {
		$types = optional_param('accepted_types', '', PARAM_RAW);
		if( $types == '*' or empty($types) or !is_array($types) or in_array('*', $types) ) {
            return null;
        }
        return $types;
	}

	private function toMimeType($value) {
		return "'".mimeinfo('type', $value)."'";
	}

	private function getWhereForMimeTypes() {
		$types = $this->getAcceptedTypes();
		if( $types == null ) {
            return null;
        }        
		return '/xml/item/attachments/attachment/mimetype IN ('.join(",", array_unique(array_map(array($this, 'toMimeType'), $types))).')';
	}

	private function getSearchResults(&$ret, $page = 1, $keywords = '') {

		if( empty($page) ) {
			$page = 1;
		}

		$searchResultsXml = $this->getSoapEndpoint()->searchItems(
			$keywords,						// Keywords to search for
			null,							// Do not restrict to a collection
			$this->getWhereForMimeTypes(),	// Possible 'where' clause
			1,								// Only live results
			1,								// Sort by date modified
			1,								// Reverse the sort - most recent changes first
			($page - 1) * $this->perpage,	// Offset of first result - $page starts are one, EQUELLA offset starts at zero
			$this->perpage					// Max results to retrieve
		);

		$ret['total'] = $searchResultsXml->nodeValue('/results/available');
		$ret['page'] = $page;
		$ret['pages'] = ceil($ret['total'] / $this->perpage);
		$ret['perpage'] = $this->perpage;

		foreach( $searchResultsXml->nodeList('/results/result') as $result ) {
			$uuid = $searchResultsXml->nodeValue('xml/item/@id', $result);
			$version = $searchResultsXml->nodeValue('xml/item/@version', $result);

			$ret['list'][] = array(
				'path' => $uuid.'/'.$version,
				'title' => htmlentities($searchResultsXml->nodeValue('xml/item/name', $result)),
				'thumbnail' => equella_full_url('thumbs/'.$uuid.'/'.$version.'/'),
				'thumbnail_width' => 120,
				'thumbnail_height' => 66,
				'children' => array(),
			);
		}
	}
	
	private function getAttachmentListing(&$ret, $uuid, $version) {
		global $CFG;

		$itemXml = $this->getSoapEndpoint()->getItem($uuid, $version);
		
		$name = htmlentities($itemXml->nodeValue('/xml/item/name'));
		$description = htmlentities($itemXml->nodeValue('/xml/item/description'));
		
		$ret['path'][] = array(
			'name' => $name,
			'path' => $uuid.'/'.$version
		);

		$baseurl = $CFG->wwwroot.'/repository/equella/redirect.php?url=';
		
		$accepted_types = $this->getAcceptedTypes();
		if( $accepted_types != null ) {
			$baseurl .= urlencode(equella_full_url('file/'.$uuid.'/'.$version.'/'));
		} else {
			$baseurl .= urlencode(equella_full_url('items/'.$uuid.'/'.$version.'/'));

			// This is the link for selecting the item summary. We put in the item description and make it span all 4 columns
			// to try and make it visually different from the other attachments. The Repository API really needs to provide
			// native support for this sort of construct - a description/summary for a listing and the ability to all selection
			// of certain folders, even they contain children.
			$ret['list'][] = array(
				'title' => $description,
				'shorttitle' => "<b>Select item - $name</b>",
				'thumbnail' => $CFG->wwwroot.'/repository/equella/pix/item-banner.png',
				'thumbnail_width' => 480,
				'thumbnail_height' => 17,
				'source' => $baseurl,
			);
		}

		foreach( $itemXml->nodeList('/xml/item/attachments/attachment') as $att ) {
			$auuid = $itemXml->nodeValue('uuid', $att);
			$afile = $itemXml->nodeValue('file', $att);

			$embed = false;
			if( preg_match('/(\.[a-z0-9]+)$/i', $afile, $match) ) {
				$embed = in_array(strtolower($match[1]), $accepted_types);
			}

			if( $accepted_types == null || $embed ) {
				if( $embed ) {
					// Double encoding on purpose - one for the filename that could contain bad entities,
					// and a second because it is all part of the value of the 'url' parameter.
					$asource = $baseurl.urlencode(urlencode($afile));
				} else {
					$asource = $baseurl.urlencode('?attachment.uuid='.$auuid);
				}

				$ret['list'][] = array(
					'title' => htmlentities($itemXml->nodeValue('description', $att)),
					'thumbnail' => equella_full_url('thumbs/'.$uuid.'/'.$version.'/'.$auuid),
					'thumbnail_width' => 120,
					'thumbnail_height' => 66,
					'source' => $asource,
				);
			}
		}
	}

	private function getSoapEndpoint() {
		$equella = new EQUELLA(equella_soap_endpoint());
		$equella->loginWithToken(equella_getssotoken());
		return $equella;
	}

	public function supported_returntypes() {
		return FILE_EXTERNAL;
	}
}

