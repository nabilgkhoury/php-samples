This repository contains the following APIs and demonstrations:

1- Extraction.php defines the Extractor and ExtractorSpecs base classes.
    Together, the two classes provide client code with a formalized way of 
    extracting and processing tabulated data (ex: CSV file).
    The file provides a demonstration that can be run as follows:
    `$ php ~/workspace/extraction.php`
2- Spawning.php defines XMLSpawning and XMLSpawner base classes.
    These classes provide client code with the ability to export arbitrary native PHP objects
    into XML using highly readable syntax made up of sample XML output and 
    corresponding callables.
    The file provides a demonstration that can be run as follows:
    `$ php ~/workspace/spawning.php`
3- Callables.php implements the Partial Function Application pattern known to
    Python developers as `functool partial`
    To run demonstration, uncomment last line of code and run as follows:
    `$ php ~/workspace/callables.php`