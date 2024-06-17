#!python3
"""
https://developer.wordpress.org/cli/commands/dist-archive/
https://github.com/mherrmann/gitignore_parser
"""
import argparse
import logging
from pathlib import Path
import os
import re
import zipfile

from gitignore_parser import parse_gitignore


if __name__ == '__main__':
    argparser = argparse.ArgumentParser()
    argparser.add_argument('directory',
                           type=Path,
                           default=Path.cwd(),
                           help='Specify the directory to archive')
    argparser.add_argument('--ignore-file-name',
                           type=str,
                           default='.distignore',
                           help='Specify a file name to ignore during the archive process')
    argparser.add_argument('--force', '-f', action='store_true', help='Overwrite existing archive')
    argparser.add_argument('--verbose', '-v', action='count', default=0, help='Increase verbosity')

    args = argparser.parse_args()

    # Set up logging.
    default_level = logging.WARNING
    logging.basicConfig(level=default_level - 10 * args.verbose)

    # Check directory exists.
    if not args.directory.exists():
        logging.error(f'{args.directory} does not exist.')
        exit(1)

    logging.info(f'Creating archive of {args.directory}')

    # Check and parse ignore file.
    ignore_file = args.directory / args.ignore_file_name
    ignore = lambda x: False
    if ignore_file:
        ignore = parse_gitignore(ignore_file, args.directory)
    else:
        logging.warning(f'{ignore_file} does not exist. Ignoring no files.')

    # Search plugin slug and version.
    plugin_slug = args.directory.name
    plugin_version = ''

    # Parse WordPress plugin style headers.
    for file in args.directory.glob('*.php'):
        content = file.read_text(encoding='utf-8', errors='ignore')

        # Get first block comment.
        if m := re.search(r'/\*.*?\*/', content, re.DOTALL):
            header = m.group(0)

            logging.debug(f'Found header {header} in {file}')

            # Find `Version` header.
            if m := re.search(r'\*\s*Version:\s*(?P<version>.+)', header):
                plugin_version = m.group('version')
                logging.debug(f'Found version {plugin_version}')

    # Generate archive name.
    archive_name = args.directory.parent / f'{plugin_slug}-{plugin_version}.zip'

    # Check if archive exists.
    if archive_name.exists():
        if args.force:
            logging.warning(f'Overwriting existing archive {archive_name.absolute()}.')
        else:
            logging.error(f'{archive_name.absolute()} already exists.')
            exit(1)

    # Create archive.
    logging.info(f'Creating archive {archive_name.absolute()}')

    with zipfile.ZipFile(archive_name, mode='w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        def add_to_archive(entry: Path, basedir: Path,  archive: zipfile.ZipFile):
            if ignore(entry):
                if entry.is_dir():
                    logging.info(f'Ignore {entry} (dir)')
                else:
                    logging.info(f'Ignore {entry}')
                return

            arcname = entry.relative_to(basedir).as_posix()
            zif = zipfile.ZipInfo.from_file(arcname)
            zif.compress_type = archive.compression

            if entry.is_dir():
                logging.debug(f'Processing {entry}')

                # Force permissions to 775.
                zif.external_attr = zif.external_attr & ~(0o777 << 16) | 0o775 << 16

                archive.writestr(zif, b'')

                for item in entry.iterdir():
                    add_to_archive(item, basedir, archive)

            else:
                logging.debug(f'Adding {arcname} from {entry}')

                # Force permissions to 664.
                zif.external_attr = zif.external_attr & ~(0o777 << 16) | 0o664 << 16

                archive.writestr(zif, entry.read_bytes())

        add_to_archive(args.directory, args.directory.parent, archive)
