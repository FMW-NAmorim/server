<?php

/**
 * Enable serving live conversion profile to the Wowza servers as XML
 * @service liveConversionProfile
 * @package plugins.wowza
 * @subpackage api.services
 */
class LiveConversionProfileService extends KalturaBaseService
{
	const MINIMAL_DEFAULT_FRAME_RATE = 12.5;
	
	/* (non-PHPdoc)
	 * @see KalturaBaseService::initService()
	 */

	public function initService($serviceId, $serviceName, $actionName)
	{
		parent::initService($serviceId, $serviceName, $actionName);
		
		$this->applyPartnerFilterForClass('conversionProfile2');
		$this->applyPartnerFilterForClass('assetParams');
	}
	
	/**
	 * Serve XML rendition of the Kaltura Live Transcoding Profile usable by the Wowza transcoding add-on
	 *
	 * @action serve
	 * @param string $streamName the id of the live entry with it's stream suffix
	 * @param string $hostname the media server host name
	 * @param string $audiodatarate
	 * @param string $videodatarate
	 * @param string $width
	 * @param string $height
	 * @param string $framerate
	 * @param string $videocodecidstring
	 * @param string $audiocodecidstring
	 * @return file
	 *
	 * @throws KalturaErrors::ENTRY_ID_NOT_FOUND
	 * @throws WowzaErrors::INVALID_STREAM_NAME
	 */
	//public function serveAction($streamName, $hostname = null)
	public function serveAction($streamName, $hostname = null, $audiodatarate = null, $videodatarate = null, $width = null, $height = null, $framerate = null, $videocodecidstring = null,  $audiocodecidstring = null)
	{
		$streamParametersArray = array(
			'streamName' => $streamName,
			'hostname' => $hostname,
			'audiodatarate' => $audiodatarate,
			'videodatarate' => $videodatarate,
			'width' => $width,
			'height' => $height,
			'framerate' => $framerate,
			'videocodecidstring' => $videocodecidstring,
			'audiocodecidstring' => $audiocodecidstring
		);
				
		$matches = null;
		if(!preg_match('/^(\d_.{8})_(\d+)$/', $streamName, $matches))
			throw new KalturaAPIException(WowzaErrors::INVALID_STREAM_NAME, $streamName);
			
		$entryId = $matches[1];
		$suffix = $matches[2];
		
		$entry = null;
		if (!kCurrentContext::$ks)
		{
			kEntitlementUtils::initEntitlementEnforcement(null, false);
			$entry = kCurrentContext::initPartnerByEntryId($entryId);
			
			if (!$entry || $entry->getStatus() == entryStatus::DELETED)
				throw new KalturaAPIException(KalturaErrors::ENTRY_ID_NOT_FOUND, $entryId);
				
			// enforce entitlement
			$this->setPartnerFilters(kCurrentContext::getCurrentPartnerId());
		}
		else 
		{	
			$entry = entryPeer::retrieveByPK($entryId);
		}
			
		if (!$entry || $entry->getType() != KalturaEntryType::LIVE_STREAM || !in_array($entry->getSource(), array(KalturaSourceType::LIVE_STREAM, KalturaSourceType::LIVE_STREAM_ONTEXTDATA_CAPTIONS)))
			throw new KalturaAPIException(KalturaErrors::ENTRY_ID_NOT_FOUND, $entryId);
			
		$mediaServer = null;
		if($hostname)
			$mediaServer = ServerNodePeer::retrieveActiveMediaServerNode($hostname);
			
		$conversionProfileId = $entry->getConversionProfileId();
		$liveParams = assetParamsPeer::retrieveByProfile($conversionProfileId);
		
		$liveParamsInput = null;
		$disableIngested = true;
		foreach($liveParams as $liveParamsItem)
		{
			/* @var $liveParamsItem liveParams */
			if($liveParamsItem->getStreamSuffix() == $suffix)
			{
				$liveParamsInput = $liveParamsItem;
				if(!$liveParamsInput->hasTag(assetParams::TAG_SOURCE))
				{
					$liveParams = array($liveParamsInput);
					$disableIngested = false;
				}
				break;
			}
		}
		
		if (!$liveParamsInput)
		{
			throw new KalturaAPIException(KalturaErrors::INGEST_NOT_FOUND_IN_CONVERSION_PROFILE, $streamName);
		}
		
		$ignoreLiveParamsIds = array();
		if($disableIngested)
		{
			$conversionProfileAssetParams = flavorParamsConversionProfilePeer::retrieveByConversionProfile($conversionProfileId);
			foreach($conversionProfileAssetParams as $conversionProfileAssetParamsItem)
			{
				/* @var $conversionProfileAssetParamsItem flavorParamsConversionProfile */
				if($conversionProfileAssetParamsItem->getOrigin() == assetParamsOrigin::INGEST)
					$ignoreLiveParamsIds[] = $conversionProfileAssetParamsItem->getFlavorParamsId();
			}
		}
		
		// translate the $liveParams to XML according to doc: http://www.wowza.com/forums/content.php?304#configTemplate
		
		$root = new SimpleXMLElement('<Root/>');
		
		$transcode = $root->addChild('Transcode');
		
		$encodes = $transcode->addChild('Encodes');
		$defaultFrameRate = null;

		$groups = array();
		foreach($liveParams as $liveParamsItem)
		{
			/* @var $liveParamsItem liveParams */
			if(!$liveParamsItem->hasTag(assetParams::TAG_SOURCE) && in_array($liveParamsItem->getId(), $ignoreLiveParamsIds))
				continue;

			if ($liveParamsItem->hasTag(assetParams::TAG_SOURCE))
			{
				if ($liveParamsItem->getFrameRate() >= self::MINIMAL_DEFAULT_FRAME_RATE)
				{
					KalturaLog::debug("Setting default frame rate to " . $liveParamsItem->getFrameRate());
					$defaultFrameRate = $liveParamsItem->getFrameRate();
				}
			}
			$this->appendLiveParams($entry, $mediaServer, $encodes, $liveParamsItem, $streamParametersArray);
			$tags = array("all");
			foreach($tags as $tag)
			{
				if(!isset($groups[$tag]))
					$groups[$tag] = array();

				$systemName = $liveParamsItem->getSystemName() ? $liveParamsItem->getSystemName() : $liveParamsItem->getId();
				$groups[$tag][] = $systemName;
			}
		}
		
		$decode = $transcode->addChild('Decode');
		$video = $decode->addChild('Video');
		$video->addChild('Deinterlace', 'false');
		
		$streamNameGroups = $transcode->addChild('StreamNameGroups');
		
		foreach($groups as $groupName => $groupMembers)
		{
			$streamNameGroup = $streamNameGroups->addChild('StreamNameGroup');
			$streamNameGroup->addChild('Name', $groupName);
			$streamNameGroup->addChild('StreamName', '${SourceStreamName}_' . $groupName);
			$members = $streamNameGroup->addChild('Members');
			
			foreach($groupMembers as $groupMember)
			{
				$member = $members->addChild('Member');
				$member->addChild('EncodeName', $groupMember);
			}
		}

		$properties = $transcode->addChild('Properties');
		if ($defaultFrameRate) {
			$property = $properties->addChild('Property');
			$property->addChild('Name', 'sourceStreamFrameRate');
			$property->addChild('Value', $defaultFrameRate);
			$property->addChild('Type', 'Double');
		}

		$dom = new DOMDocument("1.0");
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		$dom->loadXML($root->asXML());
		
		return new kRendererString($dom->saveXML(), 'text/xml');
	}
	
	private function calculateFlavorHeight($flavorResolution, $ingestParameters)
	{
		if (isset($ingestParameters['height']) && isset($ingestParameters['width']) && $ingestParameters['width'] != 0)
		{
			return $flavorResolution['width'] * $ingestParameters['height'] / $ingestParameters['width'];
		}
		return 0;
	}
	
	private function isFlavorCompatibile($ingestParameters, $flavorBitrate, $flavorResolution)
	{
		$flavorHeight = 0;
		switch ($flavorResolution['fitMode'])
		{
			case 'match-source':
				break;
			case 'fit-height':
				$flavorHeight = $flavorResolution['height'];
				break;
			case 'fit-width':
				// Flavor's height is not defined in KMC, calculate it according to ingest/flavor ratio
				$flavorHeight = $this->calculateFlavorHeight($flavorResolution, $ingestParameters);
				break;
		}
		
		if (isset($ingestParameters['height']) && $ingestParameters['height'] < $flavorHeight)
		{
			return false;
		}
		else if (isset($ingestParameters['videodatarate']) && ($ingestParameters['videodatarate'] * 1024) < $flavorBitrate)
		{
			return false;
		}
		
		return true;
	}
	
	private function getResolutionParameters($liveParams)
	{
		$resolutionObject = array(
			'fitMode' => ''
		);
		if(!$liveParams->getWidth() && !$liveParams->getHeight())
		{
			$resolutionObject['fitMode'] = 'match-source';
			$resolutionObject['value'] = 'match-source';
		}
		elseif($liveParams->getWidth() && $liveParams->getHeight())
		{
			$resolutionObject['fitMode'] = 'fit-height';
			$resolutionObject['width'] = $liveParams->getWidth();
			$resolutionObject['height'] = $liveParams->getHeight();
			$resolutionObject['value'] = $liveParams->getHeight() . ' x ' . $liveParams->getWidth();
		}
		elseif($liveParams->getWidth())
		{
			$resolutionObject['fitMode'] = 'fit-width';
			$resolutionObject['width'] = $liveParams->getWidth();
			$resolutionObject['value'] = 'fit-width x' . $liveParams->getWidth();
		}
		elseif($liveParams->getHeight())
		{
			$resolutionObject['fitMode'] = 'fit-height';
			$resolutionObject['height'] = $liveParams->getHeight();
			$resolutionObject['value'] = $liveParams->getHeight() . ' x fit-height';
		}
		
		return $resolutionObject;
	}
	
	private function checkMaxFramerate($ingestFramerate, $flavorMaxFramerate)
	{
		return $flavorMaxFramerate ? ceil(($ingestFramerate / $flavorMaxFramerate) - 1 - 0.05) : 0;
	}
	
	private function getIngestAudioCodec($ingestParameters)
	{
		return (isset($ingestParameters['audiocodecidstring']) && $ingestParameters['audiocodecidstring'] === 'AAC') ? 'PassThru' : 'AAC';
	}
	
	protected function appendLiveParams(LiveStreamEntry $entry, WowzaMediaServerNode $mediaServer = null, SimpleXMLElement $encodes, liveParams $liveParams, $streamParametersArray)
	{
		$conversionExtraParam = json_decode($liveParams->getConversionEnginesExtraParams());
		$streamName = $entry->getId() . '_' . $liveParams->getId();
		$videoCodec = 'PassThru';
		$audioCodec = $this->getIngestAudioCodec($streamParametersArray);
		$profile = 'main';
		$systemName = $liveParams->getSystemName() ? $liveParams->getSystemName() : $liveParams->getId();
		
		$flavorResolutionInfo = $this->getResolutionParameters($liveParams);
		$flavorBitrateValue = $liveParams->getVideoBitrate() ? $liveParams->getVideoBitrate() * 1024 : 240000;
		
		if (!$liveParams->hasTag(liveParams::TAG_INGEST))
		{
			// Reject all transcoded flavors that their parameters are higher that the incoming stream -> VideoBitRate, Resolution
			if (!$this->isFlavorCompatibile($streamParametersArray, $flavorBitrateValue, $flavorResolutionInfo))
			{
				// Flavor is not compatible with the ingest's parameters -> discard it.
				KalturaLog::info('Transcoded flavor [' . $liveParams->getId() . '] rejected. Resolution: [' . $flavorResolutionInfo['value'] . ']; Bitrate: [' . $flavorBitrateValue . ']');
				return;
			}
		}
		
		$encode = $encodes->addChild('Encode');
		$encode->addChild('Enable', 'true');
		$encode->addChild('Name', $systemName);
		$encode->addChild('StreamName', $streamName);
		$video = $encode->addChild('Video');
		$audio = $encode->addChild('Audio');
		
		if ($liveParams->hasTag(assetParams::TAG_AUDIO_ONLY))
		{
			$videoCodec = 'Disable';
		}
		
		if($liveParams->hasTag(liveParams::TAG_INGEST))
		{
			$video->addChild('Codec', $videoCodec);
			$audio->addChild('Codec', $audioCodec);
			if ($audioCodec !== 'PassThru') 
			{
				$audio->addChild('Bitrate', $liveParams->getAudioBitrate() ? $liveParams->getAudioBitrate() * 1024 : 96000);
			}
			return;
		}
		
		if($liveParams->getWidth() || $liveParams->getHeight() || $liveParams->getFrameRate())
		{
			switch ($liveParams->getVideoCodec())
			{
				case flavorParams::VIDEO_CODEC_COPY:
					$videoCodec = 'PassThru';
					break;
					
				case flavorParams::VIDEO_CODEC_FLV:
				case flavorParams::VIDEO_CODEC_VP6:
				case flavorParams::VIDEO_CODEC_H263:
					$profile = 'baseline';
					$videoCodec = 'H.263';
					break;
					
				case flavorParams::VIDEO_CODEC_H264:
				case flavorParams::VIDEO_CODEC_H264B:
					$profile = 'baseline';
					// don't break
					
				case flavorParams::VIDEO_CODEC_H264H:
				case flavorParams::VIDEO_CODEC_H264M:
					$streamName = "mp4:$streamName";
					$videoCodec = 'H.264';
					break;
					
				default:
					KalturaLog::err("Live params video codec id [" . $liveParams->getVideoCodec() . "] is not expected");
					break;
			}

			if($liveParams->getAudioSampleRate() || $liveParams->getAudioChannels())
			{
				switch ($liveParams->getAudioCodec())
				{
					case flavorParams::AUDIO_CODEC_AAC:
					case flavorParams::AUDIO_CODEC_AACHE:
						$audioCodec = 'AAC';
						break;
					
					default:
						KalturaLog::err("Live params audio codec id [" . $liveParams->getAudioCodec() . "] is not expected");
						break;
				}
			}
		}
		
		$video->addChild('Transcoder', $mediaServer ? $mediaServer->getTranscoder() : WowzaMediaServerNode::DEFAULT_TRANSCODER);
		$video->addChild('GPUID', $mediaServer ? $mediaServer->getGPUID() : WowzaMediaServerNode::DEFAULT_GPUID);
		$frameSize = $video->addChild('FrameSize');
		
		$frameSize->addChild('FitMode', $flavorResolutionInfo['fitMode']);
		if (isset($flavorResolutionInfo['width']))
		{
			$frameSize->addChild('Width', $flavorResolutionInfo['width']);
		}
		if (isset($flavorResolutionInfo['height']))
		{
			$frameSize->addChild('Height', $flavorResolutionInfo['height']);
		}
		
		$video->addChild('Codec', $videoCodec);
		$video->addChild('Profile', $profile);
		$video->addChild('Bitrate', $flavorBitrateValue);
		$keyFrameInterval = $video->addChild('KeyFrameInterval');
		$keyFrameInterval->addChild('FollowSource', 'true');
		$keyFrameInterval->addChild('Interval', 60);
		
		$skipFrameCountByMaxFramerate = $this->checkMaxFramerate($streamParametersArray['framerate'], $liveParams->getMaxFrameRate());
		$configuredSkipFrameRate = ($conversionExtraParam && $conversionExtraParam->skipFrameCount) ? $conversionExtraParam->skipFrameCount : 0;
		if ($configuredSkipFrameRate || $skipFrameCountByMaxFramerate)
		{
			$value = $skipFrameCountByMaxFramerate ? $skipFrameCountByMaxFramerate : $configuredSkipFrameRate;
			$skipFrameCount = $video->addChild('SkipFrameCount');
			$skipFrameCount->addChild('Value', $value);
		}
		
		if ($conversionExtraParam && $conversionExtraParam->constantBitrate)
		{
			$parameters = $video->addChild('Parameters');
			$parameter = $parameters->addChild('Parameter');
			$parameter->addChild('Name', 'mainconcept.bit_rate_mode');
			$parameter->addChild('Value', 0);
			$parameter->addChild('Type', 'Long');
		}

		$audio->addChild('Codec', $audioCodec);
		$audio->addChild('Bitrate', $liveParams->getAudioBitrate() ? $liveParams->getAudioBitrate() * 1024 : 96000);
	}
}
