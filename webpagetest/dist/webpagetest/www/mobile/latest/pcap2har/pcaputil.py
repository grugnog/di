'''
Various small, useful functions which have no other home.
'''

import dpkt

def friendly_tcp_flags(flags):
    '''
    returns a string containing a user-friendly representation of the tcp flags
    '''
    # create mapping of flags to string repr's
    d = {dpkt.tcp.TH_FIN:'FIN', dpkt.tcp.TH_SYN:'SYN', dpkt.tcp.TH_RST:'RST', dpkt.tcp.TH_PUSH:'PUSH', dpkt.tcp.TH_ACK:'ACK', dpkt.tcp.TH_URG:'URG', dpkt.tcp.TH_ECE:'ECE', dpkt.tcp.TH_CWR:'CWR'}
    #make a list of the flags that are activated
    active_flags = filter(lambda t: t[0] & flags, d.iteritems())
    #join all their string representations with '|'
    return '|'.join(t[1] for t in active_flags)

def friendly_socket(sock):
    '''
    returns a human-friendly string representing a socket in the format:
    ((sip, sport),(dip, sport))
    '''
    return '((%s, %d), (%s, %d))' % (
        sock[0][0],
        sock[0][1],
        sock[1][0],
        sock[1][1]
    )

def friendly_data(str):
    '''
    convert (possibly binary) data into a form readable by people on terminals
    '''
    return `str`

def ms_from_timedelta(td):
    '''
    gets the number of ms in td, which is datetime.timedelta.
    Modified from here:
    http://docs.python.org/library/datetime.html#datetime.timedelta, near the
    end of the section.
    '''
    return (td.microseconds + (td.seconds + td.days * 24 * 3600) * 10**6) / 10**3

def ms_from_dpkt_time(td):
    '''
    Get milliseconds from a dpkt timestamp. This should probably only really be
    done on a number gotten from subtracting two dpkt timestamps.
    '''
    return int(td * 1000) # um, I guess

class ModifiedReader(object):
    '''
    A copy of the dpkt pcap Reader. The only change is that the iterator
    yields the pcap packet header as well, so it's possible to check the true
    frame length, among other things.

    stolen from pyper.
    '''

    def __init__(self, fileobj):
        if hasattr(fileobj, 'name'):
          self.name = fileobj.name
        else:
          self.name = '<unknown>'

        if hasattr(fileobj, 'fileno'):
          self.fd = fileobj.fileno()
        else:
          self.fd = None

        self.__f = fileobj
        buf = self.__f.read(dpkt.pcap.FileHdr.__hdr_len__)
        self.__fh = dpkt.pcap.FileHdr(buf)
        self.__ph = dpkt.pcap.PktHdr
        if self.__fh.magic == dpkt.pcap.PMUDPCT_MAGIC:
            self.__fh = dpkt.pcap.LEFileHdr(buf)
            self.__ph = dpkt.pcap.LEPktHdr
        elif self.__fh.magic != dpkt.pcap.TCPDUMP_MAGIC:
            raise ValueError, 'invalid tcpdump header'
        self.snaplen = self.__fh.snaplen
        self.dloff = dpkt.pcap.dltoff[self.__fh.linktype]
        self.filter = ''

    def fileno(self):
        return self.fd

    def datalink(self):
        return self.__fh.linktype

    def setfilter(self, value, optimize=1):
        return NotImplementedError

    def readpkts(self):
        return list(self)

    def dispatch(self, cnt, callback, *args):
        if cnt > 0:
            for i in range(cnt):
                ts, pkt = self.next()
                callback(ts, pkt, *args)
        else:
            for ts, pkt in self:
                callback(ts, pkt, *args)

    def loop(self, callback, *args):
        self.dispatch(0, callback, *args)

    def __iter__(self):
        self.__f.seek(dpkt.pcap.FileHdr.__hdr_len__)
        while 1:
            buf = self.__f.read(dpkt.pcap.PktHdr.__hdr_len__)
            if not buf: break
            hdr = self.__ph(buf)
            buf = self.__f.read(hdr.caplen)
            yield (hdr.tv_sec + (hdr.tv_usec / 1000000.0), buf, hdr)
