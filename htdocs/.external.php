<?php

function processSparqlQuery($graph, $sparql)
{
  $prefixes = array();
  $lines = explode("\n", $sparql);
  foreach($lines as $index => &$line)
  {
    if(preg_match('/^\s*#/', $line))
    {
      $line = '';
    }else{
      $line = trim($line);
    }
    if(empty($line))
    {
      unset($lines[$index]);
    }else{
      if(preg_match_all('/(\w+):/', $line, $matches, PREG_SET_ORDER))
      {
        foreach($matches as $match)
        {
          $prefixes[$match[1]] = true;
        }
      }
    }
  }
  foreach($graph->ns as $prefix => $ns)
  {
    if(@$prefixes[$prefix])
    {
      array_unshift($lines, "PREFIX $prefix: <$ns>");
    }
  }
  return implode("\n", $lines);
}

function addWikidataResult($graph, $sparql)
{
  $sparql = processSparqlQuery($graph, $sparql);
  $url = 'https://query.wikidata.org/sparql?query='.rawurlencode($sparql);
  $graph->load($url);
}

function addDBPediaResult($graph, $sparql)
{
  $sparql = processSparqlQuery($graph, $sparql);
  $url = 'https://dbpedia.org/sparql/?query='.rawurlencode($sparql);
  $graph->load($url);
}

function get_updated_json_file($file, &$renew)
{
  if(file_exists($file))
  {
    if(time() - filemtime($file) >= (48 * 60 + rand(-120, 120)) * 60)
    {
      touch($file);
      $renew = true;
    }else{
      $renew = false;
    }
    $data = json_decode(file_get_contents($file), true);
    if($data === null)
    {
      $renew = true;
    }else{
      return $data;
    }
  }
  return new class implements ArrayAccess
  {
    public function offsetGet($offset)
    {
      return 'UNLOADED';
    }

    public function offsetExists($offset)
    {
      return false;
    }
    
    public function offsetSet($offset, $value)
    {
    }

    public function offsetUnset($offset)
    {
    }
  };
}

function flush_output()
{
  header('Content-Encoding: none');
  header('Content-Length: '.ob_get_length());
  header('Connection: close');
  ob_end_flush();
  ob_flush();
  flush();
}

function get_query_timeout()
{
  $timeout = filter_input(INPUT_GET, 'timeout', FILTER_VALIDATE_FLOAT);
  if($timeout === null || $timeout === false || $timeout <= 0) return 2;
  return $timeout;
}

function get_stream_context()
{
  return stream_context_create(array(
    'http' => array(
      'protocol_version' => '1.1',
      'user_agent' => 'uri4uri PHP/'.PHP_VERSION,
      'header' => 'Connection: close\r\n',
      'timeout' => get_query_timeout()
    )
  ));
}

function update_iana_records($file, $assignments, $id_element, $combine_id)
{
  libxml_set_streams_context(get_stream_context());
  $xml = new DOMDocument;
  $xml->preserveWhiteSpace = false;
  $source = "https://www.iana.org/assignments/$assignments/$assignments.xml";
  if($xml->load($source) === false)
  {
    return;
  }
  $xpath = new DOMXPath($xml);
  $xpath->registerNamespace('reg', 'http://www.iana.org/assignments');
  
  $people = array();
  foreach($xpath->query('//reg:person') as $person)
  {
    foreach($xpath->query('@id', $person) as $id_item)
    {
      $people[$id_item->nodeValue] = $person;
      break;
    }
  }
  
  $records = array();
  $registry_list = array();
  $registry_map = array();
  foreach($xpath->query('//reg:record') as $record)
  {
    foreach($xpath->query("reg:$id_element/text()", $record) as $id_item)
    {
      $id = trim($id_item->wholeText);
      $record_data = array();
      $record_data['id'] = $id;
      if($combine_id)
      {
        foreach($xpath->query("ancestor::reg:registry[. != /reg:registry]/@id", $record) as $registry_id)
        {
          $registry = $registry_id->nodeValue;
          $id = "$registry/$id";
        }
      }
      foreach($xpath->query("ancestor::reg:registry[position() = 1]/@id", $record) as $item)
      {
        $registry_name = $item->nodeValue;
        if(!isset($registry_map[$registry_name]))
        {
          $registry_id = count($registry_list);
          $registry_list[] = $registry_name;
          $registry_map[$registry_name] = $registry_id;
        }
        $record_data['registry'] = $registry_map[$registry_name];
        break;
      }
      foreach($xpath->query('@date', $record) as $item)
      {
        $record_data['date'] = $item->nodeValue;
        break;
      }
      foreach($xpath->query('@updated', $record) as $item)
      {
        $record_data['updated'] = $item->nodeValue;
        break;
      }
      foreach($xpath->query('reg:status/text()', $record) as $item)
      {
        $record_data['type'] = strtolower(trim($item->wholeText));
        break;
      }
      foreach($xpath->query('reg:name/text()', $record) as $item)
      {
        $record_data['name'] = trim($item->wholeText);
        break;
      }
      foreach($xpath->query('reg:description/text()', $record) as $item)
      {
        $record_data['description'] = trim($item->wholeText);
        break;
      }
      foreach($xpath->query('reg:protocol/text()', $record) as $item)
      {
        $record_data['protocol'] = trim($item->wholeText);
        break;
      }
      foreach($xpath->query('reg:number/text()', $record) as $item)
      {
        $record_data['number'] = trim($item->wholeText);
        break;
      }
      foreach($xpath->query('reg:allocation/text()', $record) as $item)
      {
        $record_data['created'] = trim($item->wholeText);
        break;
      }
      foreach($xpath->query('reg:termination/text()', $record) as $item)
      {
        $record_data['removed'] = trim($item->wholeText);
        break;
      }
      foreach($xpath->query('reg:well-known/reg:xref', $record) as $item)
      {
        $record_data['well-known'] = true;
        break;
      }
      $fragment = array();
      foreach($xpath->query('reg:fragment/descendant::text()', $record) as $item)
      {
        $fragment[] = trim($item->wholeText);
      }
      if(!empty($fragment))
      {
        $record_data['fragment'] = implode('\n', $fragment);
      }
      $refs = array();
      foreach($xpath->query('.//reg:xref[not(parent::reg:template)]', $record) as $xref)
      {
        foreach($xpath->query('@type', $xref) as $type_item)
        {
          foreach($xpath->query('@data', $xref) as $data_item)
          {
            $type = $type_item->nodeValue;
            $data = $data_item->nodeValue;
            if($type === 'rfc')
            {
              $refs["http://www.rfc-editor.org/rfc/$data.txt"] = strtoupper($data);
            }else if($type === 'person')
            {
              if(isset($people[$data]))
              {
                $person = $people[$data];
                $name = null;
                foreach($xpath->query('reg:name/text()', $person) as $name_item)
                {
                  $name = trim($name_item->wholeText);
                  break;
                }
                foreach($xpath->query('reg:uri/text()', $person) as $uri_item)
                {
                  $uri = str_replace('&', '@', trim($uri_item->wholeText));
                  $refs[$uri] = $name;
                  break;
                }
              }
            }else if($type === 'uri')
            {
              $refs[$data] = null;
            }
            break;
          }
          break;
        }
      }
      $record_data['refs'] = $refs;
      foreach($xpath->query('reg:file[@type="template"]/text()', $record) as $template_item)
      {
        $template = trim($template_item->wholeText);
        $record_data['template'] = "http://www.iana.org/assignments/$assignments/$template";
        break;
      }
      if(!isset($record_data['template']))
      {
        foreach($xpath->query('reg:template/reg:xref[@type="uri"]/@data', $record) as $template_item)
        {
          $record_data['template'] = $template_item->nodeValue;
          break;
        }
      }
      $id = strtolower($id);
      $record_data['additional'] = @$records[$id];
      $records[$id] = $record_data;
      break;
    }
  }
  
  ksort($records);
  
  $records['#source'] = $source;
  $records['#registry'] = $registry_list;
  
  if(file_exists($file))
  {
    file_put_contents($file, json_encode($records, JSON_UNESCAPED_SLASHES));
  }
  
  return $records;
}

function get_json_source($cache_file, $on_renew)
{
  static $in_memory = array();
  if(isset($in_memory[$cache_file]))
  {
    return $in_memory[$cache_file];
  }
  
  $data = get_updated_json_file($cache_file, $renew);
  $in_memory[$cache_file] = $data;
  if($renew)
  {
    ob_start();
    register_shutdown_function(function($cache_file, $on_renew)
    {
      flush_output();
      $on_renew($cache_file);
    }, $cache_file, $on_renew);
  }
  
  return $data;
}

function get_schemes()
{
  return get_json_source(__DIR__.'/data/schemes.json', function($cache_file)
  {
    return update_iana_records($cache_file, 'uri-schemes', 'value', false);
  });
}

function get_mime_types()
{
  return get_json_source(__DIR__.'/data/mime.json', function($cache_file)
  {
    return update_iana_records($cache_file, 'media-types', 'name', true);
  });
}

function get_mime_suffixes()
{
  return get_json_source(__DIR__.'/data/mimeplus.json', function($cache_file)
  {
    return update_iana_records($cache_file, 'media-type-structured-suffix', 'suffix', false);
  });
}

function get_special_domains()
{
  return get_json_source(__DIR__.'/data/specialdn.json', function($cache_file)
  {
    return update_iana_records($cache_file, 'special-use-domain-names', 'name', false);
  });
}

function get_locally_served_dns_zones()
{
  return get_json_source(__DIR__.'/data/localdns.json', function($cache_file)
  {
    return update_iana_records($cache_file, 'locally-served-dns-zones', 'value', false);
  });
}

function get_special_ipv4_addresses()
{
  return get_json_source(__DIR__.'/data/specialipv4.json', function($cache_file)
  {
    return update_iana_records($cache_file, 'iana-ipv4-special-registry', 'address', false);
  });
}

function get_special_ipv6_addresses()
{
  return get_json_source(__DIR__.'/data/specialipv6.json', function($cache_file)
  {
    return update_iana_records($cache_file, 'iana-ipv6-special-registry', 'address', false);
  });
}

function get_urn_namespaces()
{
  return get_json_source(__DIR__.'/data/urnns.json', function($cache_file)
  {
    return update_iana_records($cache_file, 'urn-namespaces', 'name', false);
  });
}

function get_wellknown_uris()
{
  return get_json_source(__DIR__.'/data/wellknown.json', function($cache_file)
  {
    return update_iana_records($cache_file, 'well-known-uris', 'value', false);
  });
}

function get_ports()
{
  return get_json_source(__DIR__.'/data/ports.json', function($cache_file)
  {
    return update_iana_records($cache_file, 'service-names-port-numbers', 'number', false);
  });
}

function get_services()
{
  return get_json_source(__DIR__.'/data/services.json', function($cache_file)
  {
    return update_iana_records($cache_file, 'service-names-port-numbers', 'name', false);
  });
}

function get_protocols()
{
  return get_json_source(__DIR__.'/data/protocols.json', function($cache_file)
  {
    return update_iana_records($cache_file, 'protocol-numbers', 'name', false);
  });
}

function get_tlds()
{
  return get_json_source(__DIR__.'/data/tld.json', function($cache_file)
  {
    libxml_set_streams_context(get_stream_context());
    $html = new DOMDocument;
    $source = 'https://www.iana.org/domains/root/db.html';
    if(@$html->loadHTMLFile($source) === false)
    {
      return;
    }
    $xpath = new DOMXPath($html);
    
    $domains = array();
    $domains['#source'] = $source;
    foreach($xpath->query('//table[@id="tld-table"]/tbody/tr') as $domain_item)
    {
      $cells = iterator_to_array($xpath->query('td', $domain_item));
      if(count($cells) === 3)
      {
        foreach($xpath->query('.//a', $cells[0]) as $link)
        {
          $domain_data = array();
          $name = trim($link->textContent);
          $domain_data['description'] = $name;
          $id = ltrim($name, ".");
          $domain_data['id'] = $id;
          $domain_data['url'] = $link->getAttribute('href');
          $domain_data['type'] = trim($cells[1]->textContent);
          $domain_data['sponsor'] = trim($cells[2]->textContent);
          $domains[$id] = $domain_data;
          break;
        }
      }
    }
    ksort($domains);
    
    $domains['#source'] = $source;
    
    if(file_exists($cache_file))
    {
      file_put_contents($cache_file, json_encode($domains, JSON_UNESCAPED_SLASHES));
    }
    return $domains;
  });
}

function get_purls()
{
  return get_json_source(__DIR__.'/data/purls.json', function($cache_file)
  {
    $source = 'https://archive.org/advancedsearch.php?q=collection:purl_collection%20AND%20subject:purl_data_md&fl[]=identifier&fl[]=title&fl[]=publicdate&rows=1000000&output=json';
    $data = json_decode(file_get_contents($source, false, get_stream_context()), true);
    
    $records = array();
    foreach($data['response']['docs'] as &$found)
    {
      $record = array();
      $id = $found['title'];
      $record['id'] = $id;
      $record['key'] = $found['identifier'];
      $record['date'] = @$found['publicdate'];
      $records[strtolower($id)] = $record;
    }
    $records['#source'] = $source;
    
    if(file_exists($cache_file))
    {
      file_put_contents($cache_file, json_encode($records, JSON_UNESCAPED_SLASHES));
    }
    return $records;
  });
}

function on_hsts_record($line, &$records)
{
  $record = json_decode($line, true);
  $name = $record['name'];
  $table = &$records;
  $parts = explode('.', $name);
  $len = count($parts);
  for($i = $len - 1; $i >= 0; $i--)
  {
    $key = $parts[$i];
    if(!isset($table[$key]))
    {
      $table[$key] = array();
    }
    $table = &$table[$key];
  }
  
  $flags = 0;
  if(@$record['include_subdomains'])
  {
    $flags |= 1;
  }
  if(@$record['mode'] == 'force-https')
  {
    $flags |= 2;
  }
  if($flags !== 0)
  {
    $table[''] = $flags;
  }
}

function get_hsts_domains()
{
  return get_json_source(__DIR__.'/data/hsts.json', function($cache_file)
  {
    $source = 'https://chromium.googlesource.com/chromium/src/+/main/net/http/transport_security_state_static.json?format=TEXT';
    
    $records = array();
    $records['#source'] = $source;
    
    $fp = fopen($source, 'r', false, get_stream_context());
    stream_filter_append($fp, 'convert.base64-decode');
    
    $entries = false;
    
    $multiline = array();
    
    while(($l = fgets($fp)) !== false)
    {
      $line = trim($l);
      $ncline = rtrim($line, ',');
      if(str_starts_with($line, '//')) continue;
      if(!$entries)
      {
        if($line == '{') $entries = true;
        continue;
      }
      if(str_starts_with($line, '{'))
      {
        if(str_ends_with($ncline, '}'))
        {
          on_hsts_record($ncline, $records);
        }else{
          $multiline = array($line);
        }
      }else if(!empty($multiline))
      {
        if(str_ends_with($ncline, '}'))
        {
          $multiline[] = $ncline;
          on_hsts_record(implode(' ', $multiline), $records);
          $multiline = array();
        }else{
          $multiline[] = $line;
        }
      }
    }
    
    fclose($fp);
    
    if(file_exists($cache_file))
    {
      file_put_contents($cache_file, json_encode($records, JSON_UNESCAPED_SLASHES));
    }
    return $records;
  });
}

function get_public_domain_suffixes()
{
  return get_json_source(__DIR__.'/data/pubdomains.json', function($cache_file)
  {
    $source = 'https://publicsuffix.org/list/public_suffix_list.dat';
    
    $records = array();
    $records['#source'] = $source;
    
    $fp = fopen($source, 'r', false, get_stream_context());
    
    while(($l = fgets($fp)) !== false)
    {
      $name = trim($l);
      if(empty($name) || str_starts_with($name, '//')) continue;
      
      if(str_starts_with($name, '!'))
      {
        $ispublic = false;
        $name = substr($name, 1);
      }else{
        $ispublic = true;
      }
      
      $table = &$records;
      $parts = explode('.', $name);
      $len = count($parts);
      for($i = $len - 1; $i >= 0; $i--)
      {
        $key = $parts[$i];
        if(!isset($table[$key]))
        {
          $table[$key] = array();
        }
        $table = &$table[$key];
      }
      $table[''] = $ispublic ? 1 : 0;
    }
    
    fclose($fp);
    
    if(file_exists($cache_file))
    {
      file_put_contents($cache_file, json_encode($records, JSON_UNESCAPED_SLASHES));
    }
    return $records;
  });
}

function get_rdap_record($type, $object)
{
  $url = "https://rdap.org/$type/$object";
  return json_decode(file_get_contents($url, false, get_stream_context()), true);
}

function get_whois_record($server, $object)
{
  $timeout = get_query_timeout();
  if(($socket = fsockopen($server, 43, $error_code, $error_message, $timeout)) !== false)
  {
    stream_set_timeout($socket, intval($timeout), intval(fmod($timeout, 1) * 1000000));
    fputs($socket, $object."\r\n");
    $content = stream_get_contents($socket); 
    fclose($socket);
    if(str_starts_with($content, "Your connection limit exceeded.")) return null;
    return $content;
  }
}