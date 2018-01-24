"""Utility functions and classes for the stix2 library."""

from collections import Mapping
import copy
import datetime as dt
import json

from dateutil import parser
import pytz

from .exceptions import (InvalidValueError, RevokeError,
                         UnmodifiablePropertyError)

# Sentinel value for properties that should be set to the current time.
# We can't use the standard 'default' approach, since if there are multiple
# timestamps in a single object, the timestamps will vary by a few microseconds.
NOW = object()


class STIXdatetime(dt.datetime):
    def __new__(cls, *args, **kwargs):
        precision = kwargs.pop('precision', None)
        if isinstance(args[0], dt.datetime):  # Allow passing in a datetime object
            dttm = args[0]
            args = (dttm.year, dttm.month, dttm.day, dttm.hour, dttm.minute,
                    dttm.second, dttm.microsecond, dttm.tzinfo)
        # self will be an instance of STIXdatetime, not dt.datetime
        self = dt.datetime.__new__(cls, *args, **kwargs)
        self.precision = precision
        return self

    def __repr__(self):
        return "'%s'" % format_datetime(self)


def deduplicate(stix_obj_list):
    """Deduplicate a list of STIX objects to a unique set.

    Reduces a set of STIX objects to unique set by looking
    at 'id' and 'modified' fields - as a unique object version
    is determined by the combination of those fields

    Note: Be aware, as can be seen in the implementation
    of deduplicate(),that if the "stix_obj_list" argument has
    multiple STIX objects of the same version, the last object
    version found in the list will be the one that is returned.

    Args:
        stix_obj_list (list): list of STIX objects (dicts)

    Returns:
        A list with a unique set of the passed list of STIX objects.

    """
    unique_objs = {}

    for obj in stix_obj_list:
        try:
            unique_objs[(obj['id'], obj['modified'])] = obj
        except KeyError:
            # Handle objects with no `modified` property, e.g. marking-definition
            unique_objs[(obj['id'], obj['created'])] = obj

    return list(unique_objs.values())


def get_timestamp():
    """Return a STIX timestamp of the current date and time."""
    return STIXdatetime.now(tz=pytz.UTC)


def format_datetime(dttm):
    """Convert a datetime object into a valid STIX timestamp string.

    1. Convert to timezone-aware
    2. Convert to UTC
    3. Format in ISO format
    4. Ensure correct precision
       a. Add subsecond value if non-zero and precision not defined
    5. Add "Z"

    """

    if dttm.tzinfo is None or dttm.tzinfo.utcoffset(dttm) is None:
        # dttm is timezone-naive; assume UTC
        zoned = pytz.utc.localize(dttm)
    else:
        zoned = dttm.astimezone(pytz.utc)
    ts = zoned.strftime("%Y-%m-%dT%H:%M:%S")
    ms = zoned.strftime("%f")
    precision = getattr(dttm, "precision", None)
    if precision == 'second':
        pass  # Alredy precise to the second
    elif precision == "millisecond":
        ts = ts + '.' + ms[:3]
    elif zoned.microsecond > 0:
        ts = ts + '.' + ms.rstrip("0")
    return ts + "Z"


def parse_into_datetime(value, precision=None):
    """Parse a value into a valid STIX timestamp object.
    """
    if isinstance(value, dt.date):
        if hasattr(value, 'hour'):
            ts = value
        else:
            # Add a time component
            ts = dt.datetime.combine(value, dt.time(0, 0, tzinfo=pytz.utc))
    else:
        # value isn't a date or datetime object so assume it's a string
        try:
            parsed = parser.parse(value)
        except (TypeError, ValueError):
            # Unknown format
            raise ValueError("must be a datetime object, date object, or "
                             "timestamp string in a recognizable format.")
        if parsed.tzinfo:
            ts = parsed.astimezone(pytz.utc)
        else:
            # Doesn't have timezone info in the string; assume UTC
            ts = pytz.utc.localize(parsed)

    # Ensure correct precision
    if not precision:
        return STIXdatetime(ts, precision=precision)
    ms = ts.microsecond
    if precision == 'second':
        ts = ts.replace(microsecond=0)
    elif precision == 'millisecond':
        ms_len = len(str(ms))
        if ms_len > 3:
            # Truncate to millisecond precision
            factor = 10 ** (ms_len - 3)
            ts = ts.replace(microsecond=(ts.microsecond // factor) * factor)
        else:
            ts = ts.replace(microsecond=0)
    return STIXdatetime(ts, precision=precision)


def get_dict(data):
    """Return data as a dictionary.

    Input can be a dictionary, string, or file-like object.
    """

    if type(data) is dict:
        return data
    else:
        try:
            return json.loads(data)
        except TypeError:
            pass
        try:
            return json.load(data)
        except AttributeError:
            pass
        try:
            return dict(data)
        except (ValueError, TypeError):
            raise ValueError("Cannot convert '%s' to dictionary." % str(data))


def find_property_index(obj, properties, tuple_to_find):
    """Recursively find the property in the object model, return the index
    according to the _properties OrderedDict. If it's a list look for
    individual objects.
    """
    from .base import _STIXBase
    try:
        if tuple_to_find[1] in obj._inner.values():
            return properties.index(tuple_to_find[0])
        raise ValueError
    except ValueError:
        for pv in obj._inner.values():
            if isinstance(pv, list):
                for item in pv:
                    if isinstance(item, _STIXBase):
                        val = find_property_index(item,
                                                  item.object_properties(),
                                                  tuple_to_find)
                        if val is not None:
                            return val
            elif isinstance(pv, dict):
                if pv.get(tuple_to_find[0]) is not None:
                    try:
                        return int(tuple_to_find[0])
                    except ValueError:
                        return len(tuple_to_find[0])
                for item in pv.values():
                    if isinstance(item, _STIXBase):
                        val = find_property_index(item,
                                                  item.object_properties(),
                                                  tuple_to_find)
                        if val is not None:
                            return val


def new_version(data, **kwargs):
    """Create a new version of a STIX object, by modifying properties and
    updating the ``modified`` property.
    """

    if not isinstance(data, Mapping):
        raise ValueError('cannot create new version of object of this type! '
                         'Try a dictionary or instance of an SDO or SRO class.')

    unchangable_properties = []
    if data.get("revoked"):
        raise RevokeError("new_version")
    try:
        new_obj_inner = copy.deepcopy(data._inner)
    except AttributeError:
        new_obj_inner = copy.deepcopy(data)
    properties_to_change = kwargs.keys()

    # Make sure certain properties aren't trying to change
    for prop in ["created", "created_by_ref", "id", "type"]:
        if prop in properties_to_change:
            unchangable_properties.append(prop)
    if unchangable_properties:
        raise UnmodifiablePropertyError(unchangable_properties)

    cls = type(data)
    if 'modified' not in kwargs:
        kwargs['modified'] = get_timestamp()
    elif 'modified' in data:
        old_modified_property = parse_into_datetime(data.get('modified'), precision='millisecond')
        new_modified_property = parse_into_datetime(kwargs['modified'], precision='millisecond')
        if new_modified_property < old_modified_property:
            raise InvalidValueError(cls, 'modified', "The new modified datetime cannot be before the current modified datatime.")
    new_obj_inner.update(kwargs)
    # Exclude properties with a value of 'None' in case data is not an instance of a _STIXBase subclass
    return cls(**{k: v for k, v in new_obj_inner.items() if v is not None})


def revoke(data):
    """Revoke a STIX object.

    Returns:
        A new version of the object with ``revoked`` set to ``True``.
    """
    if not isinstance(data, Mapping):
        raise ValueError('cannot revoke object of this type! Try a dictionary '
                         'or instance of an SDO or SRO class.')

    if data.get("revoked"):
        raise RevokeError("revoke")
    return new_version(data, revoked=True)


def get_class_hierarchy_names(obj):
    """Given an object, return the names of the class hierarchy."""
    names = []
    for cls in obj.__class__.__mro__:
        names.append(cls.__name__)
    return names
