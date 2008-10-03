<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2008, Phoronix Media
	Copyright (C) 2008, Michael Larabel
	pts-functions-install.php: Functions needed for installing tests and external dependencies for PTS.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program. If not, see <http://www.gnu.org/licenses/>.
*/
function pts_start_install($TO_INSTALL)
{
	if(IS_SCTP_MODE)
	{
		$tests = array($TO_INSTALL);
	}
	else
	{
		$tests = pts_contained_tests($TO_INSTALL, TRUE);

		if(count($tests) == 0)
		{
			$exit_message = "";

			if(!getenv("SILENT_INSTALL"))
				$exit_message = "\nNot recognized: $TO_INSTALL\n";

			pts_exit($exit_message);
		}
	}

	foreach($tests as $test)
		pts_install_test($test);
}
function pts_start_install_dependencies($TO_INSTALL, &$PLACE_LIST)
{
	if(IS_SCTP_MODE)
		$tests = array($TO_INSTALL);
	else
		$tests = pts_contained_tests($TO_INSTALL, TRUE);
	
	foreach($tests as $test)
		pts_install_external_dependencies_list($test, $PLACE_LIST);
}
function pts_download_test_files($identifier)
{
	// Download needed files for a test
	if(is_file(pts_location_test_resources($identifier) . "downloads.xml"))
	{
		$xml_parser = new tandem_XmlReader(pts_location_test_resources($identifier) . "downloads.xml");
		$package_url = $xml_parser->getXMLArrayValues(P_DOWNLOADS_PACKAGE_URL);
		$package_md5 = $xml_parser->getXMLArrayValues(P_DOWNLOADS_PACKAGE_MD5);
		$package_filename = $xml_parser->getXMLArrayValues(P_DOWNLOADS_PACKAGE_FILENAME);
		$package_platform = $xml_parser->getXMLArrayValues(P_DOWNLOADS_PACKAGE_PLATFORMSPECIFIC);
		$package_architecture = $xml_parser->getXMLArrayValues(P_DOWNLOADS_PACKAGE_ARCHSPECIFIC);
		$header_displayed = false;

		if(strpos(PTS_DOWNLOAD_CACHE_DIR, "://") > 0 && ($xml_dc_file = @file_get_contents(PTS_DOWNLOAD_CACHE_DIR . "pts-download-cache.xml")) != FALSE)
		{
			$xml_dc_parser = new tandem_XmlReader($xml_dc_file);
			$dc_file = $xml_dc_parser->getXMLArrayValues(P_CACHE_PACKAGE_FILENAME);
			$dc_md5 = $xml_dc_parser->getXMLArrayValues(P_CACHE_PACKAGE_MD5);
		}
		else
		{
			$dc_file = array();
			$dc_md5 = array();
		}

		for($i = 0; $i < count($package_url); $i++)
		{
			if(empty($package_filename[$i]))
				$package_filename[$i] = basename($package_url[$i]);

			$download_location = TEST_ENV_DIR . $identifier . "/";

			$file_exempt = false;

			if(!empty($package_platform[$i]))
			{
				$platforms = explode(",", $package_platform[$i]);

				foreach($platforms as $key => $value)
					$platforms[$key] = trim($value);

				if(!in_array(OPERATING_SYSTEM, $platforms))
					$file_exempt = true;
			}
			if(!empty($package_architecture[$i]))
			{
				$architectures = explode(",", $package_architecture[$i]);

				foreach($architectures as $key => $value)
					$architectures[$key] = trim($value);

				$this_arch = kernel_arch();

				if(strlen($this_arch) > 3 && substr($this_arch, -2) == "86")
					$this_arch = "x86";

				if(!in_array($this_arch, $architectures))
					$file_exempt = true;
			}

			if(!is_file($download_location . $package_filename[$i]) && !$file_exempt)
			{
				if(!$header_displayed)
				{
					$download_append = "";
					if(($size = pts_estimated_download_size($identifier)) > 0)
					{
						$download_append = "\nEstimated Download Size: " . $size . " MB";

						if(ceil(disk_free_space(TEST_ENV_DIR) / 1048576) < $size)
						{
							echo pts_string_header("There is not enough space (at " . TEST_ENV_DIR . ") for this test.");
							pts_exit();
						}
					}
					echo pts_string_header("Downloading Files For: " . $identifier . $download_append);

					$header_displayed = true;
				}

				if($package_url[$i] == $package_filename[$i])
					$urls = array();
				else
					$urls = explode(",", $package_url[$i]);

				if(count($dc_file) > 0 && count($dc_md5) > 0)
				{
					$cache_search = true;
					for($f = 0; $f < count($dc_file) && $cache_search; $f++)
					{
						if($dc_file[$f] == $package_filename[$i] && $dc_md5[$f] == $package_md5[$i])
						{
							echo pts_download(PTS_DOWNLOAD_CACHE_DIR . $package_filename[$i], $download_location);

							if(!pts_validate_md5_download_file($download_location . $package_filename[$i] . ".temp", $package_md5[$i]))
								@unlink($download_location . $package_filename[$i] . ".temp");
							else
							{
								shell_exec("cd " . $download_location . " && mv " . $package_filename[$i] . ".temp " . $package_filename[$i]);
								$urls = array();
							}

							$cache_search = false;
						}
					}
				}
				else if(pts_validate_md5_download_file(PTS_DOWNLOAD_CACHE_DIR . $package_filename[$i], $package_md5[$i]))
				{
					echo "Copying Cached File: " . $package_filename[$i] . "\n";

					if(copy(PTS_DOWNLOAD_CACHE_DIR . $package_filename[$i], $download_location . $package_filename[$i]))
						$urls = array();
				}

				if(($c = count($urls)) > 0)
				{
					if($c > 1)
						shuffle($urls);

					$fail_count = 0;
					$try_again = true;

					do
					{
						if(!IS_BATCH_MODE && pts_string_bool(pts_read_user_config(P_OPTION_PROMPT_DOWNLOADLOC, "FALSE")) && count($urls) > 1)
						{
							// Prompt user to select mirror
							do
							{
								echo "\nAvailable Download Mirrors:\n\n";
								for($j = 0; $j < count($urls); $j++)
								{
									$urls[$j] = trim($urls[$j]);
									echo ($j + 1) . ": " . $urls[$j] . "\n";
								}
								echo "\nEnter Your Preferred Mirror: ";
								$mirror_choice = trim(fgets(STDIN));
							}
							while(($mirror_choice < 1 || $mirror_choice > count($urls)) && !pts_is_valid_download_url($mirror_choice, $package_filename[$i]));

							if(is_numeric($mirror_choice))
								$url = $urls[($mirror_choice - 1)];
							else
								$url = $mirror_choice;
						}
						else
						{
							// Auto-select mirror
							do
							{
								$url = trim(array_pop($urls));
							}
							while(!pts_is_valid_download_url($url));
						}

						echo "\n\nDownloading File: " . $package_filename[$i] . "\n\n";
						echo pts_download($url, $download_location . $package_filename[$i] . ".temp");

						if(!pts_validate_md5_download_file($download_location . $package_filename[$i] . ".temp", $package_md5[$i]))
						{
							if(is_file($download_location . $package_filename[$i] . ".temp"))
								unlink($download_location . $package_filename[$i] . ".temp");

							$file_downloaded = false;
							$fail_count++;
							echo "\nThe MD5 check-sum of the downloaded file is incorrect.\n";

							if($fail_count > 3)
							{
								$try_again = false;
							}
							else
							{
								if(count($urls) > 0)
								{
									echo "Attempting to re-download from another mirror.\n";
								}
								else
								{
									$try_again = pts_bool_question("Would you like to try downloading the file again (Y/n)?", true, "TRY_DOWNLOAD_AGAIN");

									if($try_again)
										array_push($urls, $url);
									else
										$try_again = false;
								}
							}
						}
						else
						{
							if(is_file($download_location . $package_filename[$i] . ".temp"))
								shell_exec("cd " . $download_location . " && mv " . $package_filename[$i] . ".temp " . $package_filename[$i]);

							$file_downloaded = true;
							$fail_count = 0;
						}

						if(!$try_again)
						{
							pts_exit("\nDownload of Needed Test Dependencies Failed! Exiting.\n\n");
						}
					}
					while(!$file_downloaded);
				}
			}
		}
	}
}
function pts_local_download_test_files($identifier)
{
	// Names of files downloaded to the local test installation folder for the test
	$downloaded_files = array();
	if(is_file(pts_location_test_resources($identifier) . "downloads.xml"))
	{
		$xml_parser = new tandem_XmlReader(pts_location_test_resources($identifier) . "downloads.xml");
		$package_url = $xml_parser->getXMLArrayValues(P_DOWNLOADS_PACKAGE_URL);
		$package_filename = $xml_parser->getXMLArrayValues(P_DOWNLOADS_PACKAGE_FILENAME);

		for($i = 0; $i < count($package_url); $i++)
		{
			if(empty($package_filename[$i]))
				$package_filename[$i] = basename($package_url[$i]);

			array_push($downloaded_files, $package_filename[$i]);
		}
	}

	return $downloaded_files;
}
function pts_validate_md5_download_file($filename, $verified_md5)
{
	$valid = true;

	if(!is_file($filename))
	{
		$valid = false;
	}
	else
	{
		if(!empty($verified_md5))
		{
			$real_md5 = md5_file($filename);

			if(count(explode("://", $verified_md5)) > 1)
			{
				$md5_file = explode("\n", trim(@file_get_contents($verified_md5)));

				for($i = 0; $i < count($md5_file) && $valid; $i++)
				{
					$line_explode = explode(" ", trim($md5_file[$i]));

					if($line_explode[(count($line_explode) - 1)] == $filename)
					{
						if($line_explode[0] != $real_md5)
						{
							$valid = false;
						}
					}
				}
			}
			else if($real_md5 != $verified_md5)
				$valid = false;
		}
	}

	return $valid;
}
function pts_remove_local_download_test_files($identifier)
{
	// Remove locally downloaded files for a given test
	foreach(pts_local_download_test_files($identifier) as $test_file)
	{
		$file_location = TEST_ENV_DIR . $identifier . "/" . $test_file;

		if(is_file($file_location))
			@unlink($file_location);
	}
}
function pts_install_test($identifier)
{
	if(!is_test($identifier))
		return;

	// Install a test
	$installed = false;
	if(!pts_test_architecture_supported($identifier))
	{
		echo pts_string_header($identifier . " is not supported on this architecture: " . kernel_arch());
	}
	else if(!pts_test_platform_supported($identifier))
	{
		echo pts_string_header($identifier . " is not supported by this operating system (" . OPERATING_SYSTEM . ").");
	}
	else
	{
		// TODO: clean up validate-install and put in pts_validate_test_install
		$custom_validated_output = "";
		if(is_file(pts_location_test_resources($identifier) . "validate-install.sh"))
		{
			$custom_validated_output = pts_exec("cd " .  TEST_ENV_DIR . $identifier . "/ && sh " . pts_location_test_resources($identifier) . "validate-install.sh " . TEST_ENV_DIR . $identifier, pts_run_additional_vars($identifier));
		}
		else if(is_file(pts_location_test_resources($identifier) . "/validate-install.php"))
		{
			$custom_validated_output = pts_exec("cd " .  TEST_ENV_DIR . $identifier . "/ && " . PHP_BIN . " " . pts_location_test_resources($identifier) . "validate-install.php " . TEST_ENV_DIR . $identifier, pts_run_additional_vars($identifier));
		}

		if(!empty($custom_validated_output))
		{
			$custom_validated_output = trim($custom_validated_output);

			if(!pts_string_bool($custom_validated_output))
				$installed = false;
		}
		else
		{
			if(pts_test_needs_updated_install($identifier) || defined("PTS_FORCE_INSTALL"))
			{
				if(!defined("PTS_TOTAL_SIZE_MSG"))
				{
					if(isset($argv[1]))
					{
						$total_download_size = pts_estimated_download_size($argv[1]);

						if($total_download_size > 0 && is_suite($argv[1]))
							echo pts_string_header("Total Estimated Download Size: " . $total_download_size . " MB");
					}

					define("PTS_TOTAL_SIZE_MSG", 1);
				}

				if(!is_dir(TEST_ENV_DIR))
					mkdir(TEST_ENV_DIR);

				if(!is_dir(TEST_ENV_DIR . $identifier))
					mkdir(TEST_ENV_DIR . $identifier);

				pts_download_test_files($identifier);

				if(is_file(pts_location_test_resources($identifier) . "install.sh") || is_file(pts_location_test_resources($identifier) . "install.php"))
				{
					pts_module_process("__pre_test_install");
					$install_header = "Installing Test: " . $identifier;

					if(($size = pts_estimated_download_size($identifier)) > 0)
						$install_header .= "\nEstimated Install Size: " . $size . " MB";

					echo pts_string_header($install_header);

					if(!empty($size) && ceil(disk_free_space(TEST_ENV_DIR) / 1048576) < $size)
					{
						echo pts_string_header("There is not enough space (at " . TEST_ENV_DIR . ") for this test to be installed.");
						pts_exit();
					}

					$xml_parser = new pts_test_tandem_XmlReader(pts_location_test($identifier));
					$pre_install_message = $xml_parser->getXMLValue(P_TEST_PREINSTALLMSG);
					$post_install_message = $xml_parser->getXMLValue(P_TEST_POSTINSTALLMSG);

					pts_user_message($pre_install_message);

					if(is_file(pts_location_test_resources($identifier) . "install.sh"))
					{
						echo pts_exec("cd " .  TEST_ENV_DIR . $identifier . "/ && sh " . pts_location_test_resources($identifier) . "install.sh " . TEST_ENV_DIR . $identifier, pts_run_additional_vars($identifier)) . "\n";
					}
					else if(is_file(pts_location_test_resources($identifier) . "/install.php"))
					{
						echo pts_exec("cd " .  TEST_ENV_DIR . $identifier . "/ && " . PHP_BIN . " " . pts_location_test_resources($identifier) . "install.php " . TEST_ENV_DIR . $identifier, pts_run_additional_vars($identifier)) . "\n";
					}

					pts_user_message($post_install_message);

					pts_test_generate_install_xml($identifier);
					pts_module_process("__post_test_install");

					if(pts_string_bool(pts_read_user_config(P_OPTION_TEST_REMOVEDOWNLOADS, "FALSE")))
						pts_remove_local_download_test_files($identifier); // Remove original downloaded files
				}
				else
				{
					echo "No installation script found for " . $identifier . "\n";
					$installed = true;
					pts_test_generate_install_xml($identifier);
				}
			}
			else
			{
				$installed = true;
				if(!getenv("SILENT_INSTALL"))
					echo "Already Installed: " . $identifier . "\n";
			}
		}
	}
}
function pts_external_dependency_generic($Name)
{
	// Get the generic information for a PTS External Dependency generic
	$generic_information = "";

	if(is_file(XML_DISTRO_DIR . "generic-packages.xml"))
	{
		$xml_parser = new tandem_XmlReader(XML_DISTRO_DIR . "generic-packages.xml");
		$package_name = $xml_parser->getXMLArrayValues(P_EXDEP_PACKAGE_GENERIC);
		$title = $xml_parser->getXMLArrayValues(P_EXDEP_PACKAGE_TITLE);
		$possible_packages = $xml_parser->getXMLArrayValues(P_EXDEP_PACKAGE_POSSIBLENAMES);
		$file_check = $xml_parser->getXMLArrayValues(P_EXDEP_PACKAGE_FILECHECK);

		$selection = -1;
		$PTS_MANUAL_SUPPORT = 0;

		for($i = 0; $i < count($title) && $selection == -1; $i++)
		{
			if($Name == $package_name[$i])
			{
				$selection = $i;
				if(pts_file_missing_check(explode(",", $file_check[$selection])))
				{
					if($PTS_MANUAL_SUPPORT == 0)
						$PTS_MANUAL_SUPPORT = 1;

					echo pts_string_header($title[$selection] . "\nPossible Package Names: " . $possible_packages[$selection]);
				}
			}
		}

		if($PTS_MANUAL_SUPPORT == 1)
		{
			echo "The above dependencies should be installed before proceeding. Press any key when you're ready to continue.";
			fgets(STDIN);
		}
	}

	return $generic_information;
}
function pts_file_missing_check($file_arr)
{
	// Checks if file is missing
	$file_missing = false;

	foreach($file_arr as $file)
	{
		$file_is_there = false;
		$file = explode("OR", $file);

		for($i = 0; $i < count($file) && $file_is_there == false; $i++)
		{
			$file[$i] = trim($file[$i]);

			if(is_file($file[$i]) || is_dir($file[$i]) || is_link($file[$i]))
				$file_is_there = true;
		}
		$file_missing = $file_missing || !$file_is_there;
	}

	return $file_missing;
}
function pts_install_package_on_distribution($identifier)
{
	// PTS External Dependencies install on distribution
	if(getenv("SILENT_INSTALL") == FALSE)
		echo "Checking For Needed External Dependencies.\n";

	$identifier = strtolower($identifier);
	$install_objects = array();
	pts_start_install_dependencies($identifier, $install_objects);
	pts_install_packages_on_distribution_process($install_objects);
}
function pts_install_packages_on_distribution_process($install_objects)
{
	// Do the actual installing process of packages using the distribution's package management system
	if(!empty($install_objects))
	{
		if(is_array($install_objects))
			$install_objects = implode(" ", $install_objects);

		$distribution = pts_vendor_identifier();

		if(is_file(SCRIPT_DISTRO_DIR . "install-" . $distribution . "-packages.sh") || is_link(SCRIPT_DISTRO_DIR . "install-" . $distribution . "-packages.sh"))
		{
			echo "\nThe following dependencies will be installed: \n";

			foreach(explode(" ", $install_objects) as $obj)
				echo "- " . $obj . "\n";

			echo "\nThis process may take several minutes.\n";

			echo shell_exec("cd " . SCRIPT_DISTRO_DIR . " && sh install-" . $distribution . "-packages.sh " . $install_objects);
		}
		else
			echo "Distribution install script not found!";
	}
}
function pts_install_external_dependencies_list($identifier, &$INSTALL_OBJ)
{
	// Install from a list of external dependencies
	if(!is_test($identifier))
		return;

	$xml_parser = new pts_test_tandem_XmlReader(pts_location_test($identifier));
	$title = $xml_parser->getXMLValue(P_TEST_TITLE);
	$dependencies = $xml_parser->getXMLValue(P_TEST_EXDEP);

	if(!empty($dependencies))
	{
		$dependencies = explode(",", $dependencies);

		for($i = 0; $i < count($dependencies); $i++)
			$dependencies[$i] = trim($dependencies[$i]);

		if(!defined("PTS_EXDEP_FIRST_RUN"))
		{
			array_push($dependencies, "php-extras");

			if(kernel_arch() == "x86_64")
				array_push($dependencies, "linux-32bit-libraries");

			define("PTS_EXDEP_FIRST_RUN", 1);
		}

		$vendor = pts_vendor_identifier();

		if(!pts_package_generic_to_distro_name($INSTALL_OBJ, $dependencies))
		{
			$package_string = "";
			foreach($dependencies as $dependency)
			{
				$package_string .= pts_external_dependency_generic($dependency);
			}

			if(!empty($package_string))
				echo "\nSome additional dependencies are required to run or more of these tests, and they could not be installed automatically for your distribution. Below are the software packages that must be installed for the test(s) to run properly.\n\n" . $package_string;
		}
	}
}
function pts_package_generic_to_distro_name(&$package_install_array, $generic_names)
{
	// Generic name to distribution package name
	$vendor = pts_vendor_identifier();
	$generated = false;

	if(is_file(XML_DISTRO_DIR . $vendor . "-packages.xml"))
	{
		$xml_parser = new tandem_XmlReader(XML_DISTRO_DIR . $vendor . "-packages.xml");
		$generic_package = $xml_parser->getXMLArrayValues(P_EXDEP_PACKAGE_GENERIC);
		$distro_package = $xml_parser->getXMLArrayValues(P_EXDEP_PACKAGE_SPECIFIC);
		$file_check = $xml_parser->getXMLArrayValues(P_EXDEP_PACKAGE_FILECHECK);

		for($i = 0; $i < count($generic_package); $i++)
			if(!empty($generic_package[$i]) && in_array($generic_package[$i], $generic_names))
			{
				if(!in_array($distro_package[$i], $package_install_array))
				{
					if(!empty($file_check[$i]))
					{
						$files = explode(",", $file_check[$i]);
						$add_dependency = pts_file_missing_check($files);
					}
					else
						$add_dependency = true;

					if($add_dependency)
						array_push($package_install_array, $distro_package[$i]);
				}
			}
		$generated = true;
	}

	return $generated;
}

?>
